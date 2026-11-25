<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\CrossPurposeDuplicates;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The same mail delivered to addresses of two purposes is two messages that know
 * of each other; other bytes under one Message-ID, or another address of the same
 * purpose, do not count.
 */
final class CrossPurposeDuplicatesTest extends KernelTestCase
{
    private MailKit $kit;
    private AddressIssuer $issuer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->issuer = $container->get(AddressIssuer::class);
        $this->kit = MailKit::seed($container->get(EntityManagerInterface::class), $this->issuer);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        parent::tearDown();
    }

    public function testAMessageReceivedUnderTwoPurposesIsACrossPurposeDuplicate(): void
    {
        $other = $this->issuer->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Other')->address;
        $raw = MailKit::mail($this->kit->address);

        $first = $this->deliver($raw, $this->kit->address);
        $second = $this->deliver($raw, $other);

        self::assertSame([['messageId' => $second, 'purpose' => RecordingPurpose::RESHOWABLE_KEY]], $this->duplicates()->of($first));
        self::assertSame([['messageId' => $first, 'purpose' => RecordingPurpose::KEY]], $this->duplicates()->of($second));
    }

    public function testSameMessageIdWithOtherBytesIsNot(): void
    {
        $other = $this->issuer->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Other')->address;

        $first = $this->deliver(MailKit::mail($this->kit->address), $this->kit->address);
        $second = $this->deliver(MailKit::mail($other, body: "Something else entirely.\r\n"), $other);

        self::assertSame([], $this->duplicates()->of($first));
        self::assertSame([], $this->duplicates()->of($second));
    }

    public function testTwoAddressesOfOnePurposeAreNotCrossPurpose(): void
    {
        $other = $this->issuer->issue(RecordingPurpose::KEY, null, 'Other')->address;
        $raw = MailKit::mail($this->kit->address);

        $first = $this->deliver($raw, $this->kit->address);
        $this->deliver($raw, $other);

        self::assertSame([], $this->duplicates()->of($first));
    }

    public function testAMessageWithoutAMessageIdAndAnUnknownIdHaveNone(): void
    {
        $other = $this->issuer->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Other')->address;
        $raw = MailKit::mail($this->kit->address, null);

        $first = $this->deliver($raw, $this->kit->address);
        $this->deliver($raw, $other);

        self::assertSame([], $this->duplicates()->of($first));
        self::assertSame([], $this->duplicates()->of('00000000-0000-4000-8000-000000000000'));
    }

    /**
     * The raw bytes handed to the address by the recipient the webhook names, so the same bytes reach two addresses.
     */
    private function deliver(string $raw, string $address): string
    {
        $outcome = self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $address);
        self::assertSame(ReceiveOutcome::Stored, $outcome);
        $token = self::getContainer()->get(InboundAddresses::class)->tokenIn($address);
        self::assertNotNull($token);
        $id = self::getContainer()->get(Connection::class)->fetchOne('SELECT m.id FROM mail_message m JOIN mail_inbound_address a ON a.id = m.address_id WHERE a.token_hash = :hash AND m.raw_sha256 = :sha', ['hash' => \Foresi\Mail\Application\InboundToken::hash($token), 'sha' => hash('sha256', $raw)]);
        self::assertIsString($id);

        return $id;
    }

    private function duplicates(): CrossPurposeDuplicates
    {
        return self::getContainer()->get(CrossPurposeDuplicates::class);
    }
}
