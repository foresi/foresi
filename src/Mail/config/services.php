<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Mail\Application\DailyLimit;
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
use Foresi\Mail\Application\StoredRetention;
use Foresi\Mail\Application\TrustSettings;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\CrossPurposeDuplicates;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Infrastructure\CachePollFailures;
use Foresi\Mail\Infrastructure\Doctrine\DoctrineCrossPurposeDuplicates;
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
    $builder->registerForAutoconfiguration(InboundPurpose::class)->addTag(InboundPurpose::TAG);
    $services->set(InboundPurposes::class)
        ->arg('$purposes', tagged_iterator(InboundPurpose::TAG));

    $services->alias(MailMessages::class, DoctrineMailMessages::class);
    $services->alias(InboundAddressStore::class, DoctrineInboundAddresses::class);
    $services->alias(MailTransaction::class, DoctrineMailTransaction::class);
    $services->alias(MailParts::class, ZbatesonMailReader::class);
    $services->alias(Messages::class, StoredMessages::class);
    $services->alias(AddressIssuer::class, IssueAddresses::class);
    $services->alias(CrossPurposeDuplicates::class, DoctrineCrossPurposeDuplicates::class);
    $services->alias(MessageRetention::class, StoredRetention::class);

    // Raw mail as it arrived; never below public/. Back it up with the database.
    $container->parameters()->set('app.inbound_mail_dir', '%kernel.share_dir%/inbound-mail');

    $services->alias(MailReader::class, ZbatesonMailReader::class);
    // MAIL_INBOUND_* settings; an installation that still sets the old BANK_INBOX_* names keeps
    // working for one more release, with a deprecation warning (LegacyMailSettings).
    // Whose authentication results are believed, per channel; empty lists verify no message.
    // The webhook has its own list and never falls back to the mailbox's.
    $services->set(TrustSettings::class)
        ->arg('$authservIds', '%env(csv:mail_inbound:TRUSTED_AUTHSERV)%')
        ->arg('$arcSealers', '%env(csv:mail_inbound:TRUSTED_ARC)%')
        ->arg('$webhookAuthservIds', '%env(csv:INBOUND_WEBHOOK_TRUSTED_AUTHSERV)%');
    // The inbound address template; empty means inbound mail is not set up here.
    $services->set(InboundAddresses::class)
        ->arg('$template', '%env(string:mail_inbound:ADDRESS)%');
    // How many mails one address takes in an instance day; the rest is dropped. The default
    // keeps an installation whose own .env predates the setting running.
    $container->parameters()->set('env(MAIL_INBOUND_DAILY_LIMIT)', '500');
    $services->set(DailyLimit::class)
        ->arg('$limit', '%env(int:MAIL_INBOUND_DAILY_LIMIT)%');
    // The operator mailbox mail is polled from; none when MAIL_INBOUND_HOST is empty.
    $services->set(InboundMailbox::class)
        ->factory([ImapInboundMailbox::class, 'fromSettings'])
        ->arg('$host', '%env(string:mail_inbound:HOST)%')
        ->arg('$port', '%env(int:mail_inbound:PORT)%')
        ->arg('$encryption', '%env(string:mail_inbound:ENCRYPTION)%')
        ->arg('$user', '%env(string:mail_inbound:USER)%')
        ->arg('$password', '%env(string:mail_inbound:PASSWORD)%')
        ->arg('$folder', '%env(string:mail_inbound:FOLDER)%');
    // How often a message of the operator mailbox failed, across polls.
    $services->alias(PollFailures::class, CachePollFailures::class);
    $services->alias(InboundFiles::class, DiskInboundFiles::class);
    $services->set(DiskInboundFiles::class)
        ->arg('$root', param('app.inbound_mail_dir'));

    if ('test' === $container->env()) {
        // Inside the test cache, so a test never writes to the real share directory.
        $container->parameters()->set('app.inbound_mail_dir', '%kernel.cache_dir%/inbound-mail');

        // Contracts nothing in a test boot uses yet, reached by the Mail tests.
        $services->alias(CrossPurposeDuplicates::class, DoctrineCrossPurposeDuplicates::class)->public();
        $services->alias(MessageRetention::class, StoredRetention::class)->public();

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
        // One that belongs to a person, as the addresses people manage themselves do.
        $services->set('test.mail_purpose_owned', RecordingPurpose::class)
            ->args([RecordingPurpose::OWNED_KEY, false, true]);
    }

    if ('e2e' === $container->env()) {
        // The mail of the seed, gone with the e2e cache that serve.sh starts from.
        $container->parameters()->set('app.inbound_mail_dir', '%kernel.cache_dir%/inbound-mail');
        // Public handle for tests/e2e/seed.php.
        $services->alias('e2e.receive_inbound_mail', ReceiveMail::class)->public();
    }
};
