<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Banking\Application\BankMessageParsers;
use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\Candidates;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Application\InboundAddresses;
use Foresi\Banking\Application\InboundFiles;
use Foresi\Banking\Application\InboundMailbox;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\InboundProcessing;
use Foresi\Banking\Application\MailReader;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\Ownership;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\PendingChanges;
use Foresi\Banking\Application\PollFailures;
use Foresi\Banking\Application\ReceiveInboundMail;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustSettings;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Contract\BankMessageParser;
use Foresi\Banking\Infrastructure\CachePollFailures;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineBankMovements;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineCandidates;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineFirmPairings;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineInboundMessages;
use Foresi\Banking\Infrastructure\Doctrine\DoctrinePendingChanges;
use Foresi\Banking\Infrastructure\Files\DiskInboundFiles;
use Foresi\Banking\Infrastructure\IdentityOwnership;
use Foresi\Banking\Infrastructure\Imap\ImapInboundMailbox;
use Foresi\Banking\Infrastructure\Messenger\MessengerInboundProcessing;
use Foresi\Banking\Infrastructure\Mime\ZbatesonMailReader;
use Foresi\Tests\Banking\Support\FailingInboundFiles;
use Foresi\Tests\Support\Mail\FakeInboundMailbox;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Banking module, loaded by Foresi\Banking\BankingBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Banking\\', '../')
        ->exclude(['../config/', '../Integration/', '../BankingBundle.php']);

    // Raw bank mail as it arrived; never below public/. Back it up with the database.
    $container->parameters()->set('app.inbound_mail_dir', '%kernel.share_dir%/inbound-mail');

    // A country module plugs into banking with the parsers of its banks' mail.
    $builder->registerForAutoconfiguration(BankMessageParser::class)->addTag('app.bank_message_parser');

    $services->set(BankMessageParsers::class)
        ->arg('$parsers', tagged_iterator('app.bank_message_parser'));

    $services->alias(MailReader::class, ZbatesonMailReader::class);
    // Whose authentication results are believed, per channel; empty lists verify no message.
    // The webhook has its own list and never falls back to the mailbox's.
    $services->set(TrustSettings::class)
        ->arg('$authservIds', '%env(csv:BANK_INBOX_TRUSTED_AUTHSERV)%')
        ->arg('$arcSealers', '%env(csv:BANK_INBOX_TRUSTED_ARC)%')
        ->arg('$webhookAuthservIds', '%env(csv:INBOUND_WEBHOOK_TRUSTED_AUTHSERV)%');
    // A firm's stored switches, or the defaults while it has none.
    $services->alias(FirmPairingRules::class, DoctrineFirmPairings::class);
    // The inbound address of a bank account; empty means bank mail is not set up here.
    $services->set(InboundAddresses::class)
        ->arg('$template', '%env(string:BANK_INBOX_ADDRESS)%');
    $services->alias(BankMovements::class, DoctrineBankMovements::class);
    $services->alias(Candidates::class, DoctrineCandidates::class);
    // The operator mailbox bank mail is polled from; none when BANK_INBOX_HOST is empty.
    $services->set(InboundMailbox::class)
        ->factory([ImapInboundMailbox::class, 'fromSettings'])
        ->arg('$host', '%env(string:BANK_INBOX_HOST)%')
        ->arg('$port', '%env(int:BANK_INBOX_PORT)%')
        ->arg('$encryption', '%env(string:BANK_INBOX_ENCRYPTION)%')
        ->arg('$user', '%env(string:BANK_INBOX_USER)%')
        ->arg('$password', '%env(string:BANK_INBOX_PASSWORD)%')
        ->arg('$folder', '%env(string:BANK_INBOX_FOLDER)%');
    $services->alias(InboundMessages::class, DoctrineInboundMessages::class);
    $services->alias(Ownership::class, IdentityOwnership::class);
    // How often a message of the operator mailbox failed, across polls.
    $services->alias(PollFailures::class, CachePollFailures::class);
    $services->alias(InboundFiles::class, DiskInboundFiles::class);
    $services->alias(InboundProcessing::class, MessengerInboundProcessing::class);
    $services->alias(PendingChanges::class, DoctrinePendingChanges::class);
    $services->set(DiskInboundFiles::class)
        ->arg('$root', param('app.inbound_mail_dir'));

    if ('test' === $container->env()) {
        // Inside the test cache, so a test never writes to the real share directory.
        $container->parameters()->set('app.inbound_mail_dir', '%kernel.cache_dir%/inbound-mail');

        // The operator mailbox in memory, and an inbound file store a test can make fail.
        $services->set(FakeInboundMailbox::class);
        $services->alias(InboundMailbox::class, FakeInboundMailbox::class)->public();
        $services->set(FailingInboundFiles::class)->decorate(InboundFiles::class);
        // The poller's failure counts in memory, so no test leaves them for the next.
        $services->set(CachePollFailures::class)
            ->arg('$cache', inline_service(ArrayAdapter::class));

        // Public for the banking tests and the worker of the pairing race, which drive them directly.
        $services->alias(BankMovements::class, DoctrineBankMovements::class)->public();
        foreach ([RecordMovement::class, PairMovement::class, UnpairMovement::class, Matcher::class] as $service) {
            $services->set($service)->public();
        }
    }

    if ('e2e' === $container->env()) {
        // The bank mail of the seed, gone with the e2e cache that serve.sh starts from.
        $container->parameters()->set('app.inbound_mail_dir', '%kernel.cache_dir%/inbound-mail');

        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.record_movement', RecordMovement::class)->public();
        $services->alias('e2e.pair_movement', PairMovement::class)->public();
        $services->alias('e2e.generate_address', GenerateAddress::class)->public();
        $services->alias('e2e.receive_inbound_mail', ReceiveInboundMail::class)->public();
    }
};
