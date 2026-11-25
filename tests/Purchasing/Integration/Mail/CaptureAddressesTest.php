<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Mail;

use Doctrine\DBAL\Connection;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Application\IssueAddresses;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Purchasing\Application\Mail\CaptureAddressBook;
use Foresi\Purchasing\Application\Mail\CaptureAddresses;
use Foresi\Purchasing\Application\Mail\CaptureAddressRefusal;
use Foresi\Purchasing\Application\Mail\IssuedCaptureAddress;
use Foresi\Purchasing\Application\Mail\ReceivedDocumentsPurpose;
use Foresi\Purchasing\Application\Mail\UnknownCaptureAddress;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Shared\Application\Sealer;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;
use Foresi\Tests\Support\Mail\SyntheticMail;
use Psr\Clock\ClockInterface;

/**
 * Each member of the organisation has one capture address for received
 * documents: Mail keeps the address and its token, Purchasing the person's name
 * for it. Everyone works on their own address only.
 */
final class CaptureAddressesTest extends PurchasingTestCase
{
    public function testCreateOnePerPerson(): void
    {
        $issued = $this->addresses()->create('  My documents ');

        self::assertSame('My documents', $issued->label);
        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $issued->address);
        $mine = $this->addresses()->mine();
        self::assertNotNull($mine);
        self::assertSame($issued->id, $mine->id);
        self::assertSame('My documents', $mine->label);
        self::assertSame($issued->address, $mine->address, 'The address is shown again.');
        self::assertEquals(new \DateTimeImmutable('2026-11-10 09:00:00'), $mine->createdAt);
        self::assertNull($mine->lastReceivedAt);

        // Mail keeps one address of the purpose, owned by the person, under its fixed label.
        $row = $this->connection()->fetchAssociative('SELECT purpose, owner_user_id, label FROM mail_inbound_address');
        self::assertSame([ReceivedDocumentsPurpose::KEY, $this->owner->user()->id(), ReceivedDocumentsPurpose::LABEL], array_values((array) $row));

        // Another person has an address of their own.
        $this->signIn($this->member);
        self::assertNull($this->addresses()->mine());
        $other = $this->addresses()->create('Marek');
        self::assertNotSame($issued->address, $other->address);
        self::assertSame(2, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM purchasing_capture_address')));
    }

    public function testSecondCreateIsRefused(): void
    {
        $this->addresses()->create('First');

        try {
            $this->addresses()->create('Second');
            self::fail('A second address was issued.');
        } catch (CaptureAddressRefusal $refused) {
            self::assertSame('capture_address_exists', $refused->reason());
        }
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM purchasing_capture_address')));
    }

    public function testRenameKeepsTheAddress(): void
    {
        $issued = $this->addresses()->create('Old name');

        $this->addresses()->rename($issued->id, 'New name');

        $mine = $this->addresses()->mine();
        self::assertNotNull($mine);
        self::assertSame('New name', $mine->label);
        self::assertSame($issued->address, $mine->address);
        self::assertSame($issued->address, $this->addresses()->reveal($issued->id));
        self::assertSame(ReceivedDocumentsPurpose::LABEL, $this->connection()->fetchOne('SELECT label FROM mail_inbound_address'));

        try {
            $this->addresses()->rename($issued->id, '  ');
            self::fail('A blank name was taken.');
        } catch (CaptureAddressRefusal $refused) {
            self::assertSame('label_required', $refused->reason());
        }
    }

    public function testReplaceStopsTheOldAddress(): void
    {
        $issued = $this->addresses()->create('Mine');

        $replaced = $this->addresses()->replace($issued->id);

        self::assertSame($issued->id, $replaced->id, 'The same address of the person, with a new token.');
        self::assertNotSame($issued->address, $replaced->address);
        self::assertSame($replaced->address, $this->addresses()->reveal($issued->id));
        // The old token is not found any more, and a mail to it is treated as one for an unknown address.
        $store = self::getContainer()->get(InboundAddressStore::class);
        self::assertNull($store->routable($this->hashOf($issued->address)));
        self::assertNotNull($store->routable($this->hashOf($replaced->address)));
        $outcome = self::getContainer()->get(ReceiveMail::class)->receive(SyntheticMail::plain($issued->address), InboundChannel::Webhook);
        self::assertSame(ReceiveOutcome::UnknownRecipient, $outcome);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_message')));
    }

    public function testRevoke(): void
    {
        $issued = $this->addresses()->create('Mine');

        $this->addresses()->revoke($issued->id);

        self::assertNull($this->addresses()->mine());
        self::assertNull(self::getContainer()->get(InboundAddressStore::class)->routable($this->hashOf($issued->address)));
        self::assertNotNull($this->connection()->fetchOne('SELECT revoked_at FROM mail_inbound_address'));
        self::assertNotNull($this->connection()->fetchOne('SELECT revoked_at FROM purchasing_capture_address'));
        // A revoked address is not one of the person's any more.
        $this->expectException(UnknownCaptureAddress::class);
        $this->addresses()->reveal($issued->id);
    }

    public function testCreateAfterRevoke(): void
    {
        $first = $this->addresses()->create('First');
        $this->addresses()->revoke($first->id);

        $second = $this->addresses()->create('Second');

        self::assertNotSame($first->id, $second->id);
        self::assertNotSame($first->address, $second->address);
        $mine = $this->addresses()->mine();
        self::assertNotNull($mine);
        self::assertSame($second->id, $mine->id);
        self::assertSame(2, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM purchasing_capture_address')), 'The revoked one stays as history.');
    }

    public function testGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        try {
            $this->addresses()->create('Greta');
        } finally {
            self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
        }
    }

    public function testAnotherPersonsAddressIsUnknown(): void
    {
        $issued = $this->addresses()->create('Olga');
        $this->signIn($this->member);

        foreach ([
            fn () => $this->addresses()->rename($issued->id, 'Mine now'),
            fn (): IssuedCaptureAddress => $this->addresses()->replace($issued->id),
            fn (): ?string => $this->addresses()->reveal($issued->id),
            fn () => $this->addresses()->revoke($issued->id),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('The address of another person was reached.');
            } catch (UnknownCaptureAddress $unknown) {
                self::assertSame('not_found', $unknown->reason());
            }
        }
        // Nothing of Olga's changed.
        $this->signIn($this->owner);
        $mine = $this->addresses()->mine();
        self::assertNotNull($mine);
        self::assertSame('Olga', $mine->label);
        self::assertSame($issued->address, $mine->address);
    }

    public function testUnavailableWithoutTemplate(): void
    {
        $addresses = $this->addressesFor(new InboundAddresses(''));

        try {
            $addresses->create('Mine');
            self::fail('An address was issued without a template.');
        } catch (CaptureAddressRefusal $refused) {
            self::assertSame('not_configured', $refused->reason());
            self::assertSame(503, $refused->kind()->status());
        }
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM purchasing_capture_address')));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
    }

    private function addresses(): CaptureAddresses
    {
        return self::getContainer()->get(CaptureAddresses::class);
    }

    private function addressesFor(InboundAddresses $template): CaptureAddresses
    {
        $container = self::getContainer();
        $issuer = new IssueAddresses(
            $template,
            $container->get(InboundAddressStore::class),
            $container->get(InboundPurposes::class),
            $container->get(Sealer::class),
            $container->get(MailTransaction::class),
            $container->get(ClockInterface::class),
        );

        return new CaptureAddresses(
            $container->get(PurchasingAccess::class),
            $container->get(PurchasingUnit::class),
            $container->get(CaptureAddressBook::class),
            $issuer,
            $container->get(ClockInterface::class),
        );
    }

    private function hashOf(string $address): string
    {
        return InboundToken::hash((string) new InboundAddresses('bank+{token}@example.com')->tokenIn($address));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
