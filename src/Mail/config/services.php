<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundFiles;
use Foresi\Mail\Application\InboundMailbox;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\IssueAddresses;
use Foresi\Mail\Application\MailMessages;
use Foresi\Mail\Application\MailParts;
use Foresi\Mail\Application\MailReader;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Application\PollFailures;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\StoredMessages;
use Foresi\Mail\Application\TrustSettings;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Infrastructure\CachePollFailures;
use Foresi\Mail\Infrastructure\Doctrine\DoctrineInboundAddresses;
use Foresi\Mail\Infrastructure\Doctrine\DoctrineMailMessages;
use Foresi\Mail\Infrastructure\Doctrine\DoctrineMailTransaction;
use Foresi\Mail\Infrastructure\Files\DiskInboundFiles;
use Foresi\Mail\Infrastructure\Imap\ImapInboundMailbox;
use Foresi\Mail\Infrastructure\Mime\ZbatesonMailReader;
use Foresi\Tests\Support\Mail\FailingInboundFiles;
use Foresi\Tests\Support\Mail\FakeInboundMailbox;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Mail module, loaded by Foresi\Mail\MailBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Mail\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../MailBundle.php']);

    // A module takes the mail of its own addresses by registering a purpose.
    $builder->registerForAutoconfiguration(InboundPurpose::class)->addTag('app.mail_inbound_purpose');
    $services->set(InboundPurposes::class)
        ->arg('$purposes', tagged_iterator('app.mail_inbound_purpose'));

    $services->alias(MailMessages::class, DoctrineMailMessages::class);
    $services->alias(InboundAddressStore::class, DoctrineInboundAddresses::class);
    $services->alias(MailTransaction::class, DoctrineMailTransaction::class);
    $services->alias(MailParts::class, ZbatesonMailReader::class);
    $services->alias(Messages::class, StoredMessages::class);
    $services->alias(AddressIssuer::class, IssueAddresses::class);

    // Raw mail as it arrived; never below public/. Back it up with the database.
    $container->parameters()->set('app.inbound_mail_dir', '%kernel.share_dir%/inbound-mail');

    $services->alias(MailReader::class, ZbatesonMailReader::class);
    // Whose authentication results are believed, per channel; empty lists verify no message.
    // The webhook has its own list and never falls back to the mailbox's.
    $services->set(TrustSettings::class)
        ->arg('$authservIds', '%env(csv:BANK_INBOX_TRUSTED_AUTHSERV)%')
        ->arg('$arcSealers', '%env(csv:BANK_INBOX_TRUSTED_ARC)%')
        ->arg('$webhookAuthservIds', '%env(csv:INBOUND_WEBHOOK_TRUSTED_AUTHSERV)%');
    // The inbound address template; empty means inbound mail is not set up here.
    $services->set(InboundAddresses::class)
        ->arg('$template', '%env(string:BANK_INBOX_ADDRESS)%');
    // The operator mailbox mail is polled from; none when BANK_INBOX_HOST is empty.
    $services->set(InboundMailbox::class)
        ->factory([ImapInboundMailbox::class, 'fromSettings'])
        ->arg('$host', '%env(string:BANK_INBOX_HOST)%')
        ->arg('$port', '%env(int:BANK_INBOX_PORT)%')
        ->arg('$encryption', '%env(string:BANK_INBOX_ENCRYPTION)%')
        ->arg('$user', '%env(string:BANK_INBOX_USER)%')
        ->arg('$password', '%env(string:BANK_INBOX_PASSWORD)%')
        ->arg('$folder', '%env(string:BANK_INBOX_FOLDER)%');
    // How often a message of the operator mailbox failed, across polls.
    $services->alias(PollFailures::class, CachePollFailures::class);
    $services->alias(InboundFiles::class, DiskInboundFiles::class);
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
        // Purposes of the Mail tests, one that never shows its addresses again and one that does.
        $services->set('test.mail_purpose', RecordingPurpose::class)
            ->args([RecordingPurpose::KEY, false]);
        $services->set('test.mail_purpose_reshowable', RecordingPurpose::class)
            ->args([RecordingPurpose::RESHOWABLE_KEY, true]);
    }

    if ('e2e' === $container->env()) {
        // The mail of the seed, gone with the e2e cache that serve.sh starts from.
        $container->parameters()->set('app.inbound_mail_dir', '%kernel.cache_dir%/inbound-mail');
        // Public handle for tests/e2e/seed.php.
        $services->alias('e2e.receive_inbound_mail', ReceiveMail::class)->public();
    }
};
