<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundFiles;
use App\Banking\Application\ProcessInboundMessage;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\ReceiveOutcome;
use App\Banking\Domain\InboundChannel;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Banking\Support\FailingInboundFiles;
use App\Tests\Banking\Support\InboundMailKit;
use App\Tests\Banking\Support\Scalars;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Inbound mail is routed by the token in its recipient, stored as a file and a
 * row in the token's tenant, and queued for processing. The same message twice
 * is one row, and nothing of an unknown recipient is kept.
 */
final class ReceiveInboundMailTest extends KernelTestCase
{
    private InboundMailKit $kit;
    private ReceiveInboundMail $receive;
    private Connection $connection;
    private InMemoryTransport $queue;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->kit = InboundMailKit::seed($container->get(EntityManagerInterface::class), $container->get(TenantContext::class), $container->get(GenerateAddress::class));
        $this->receive = $container->get(ReceiveInboundMail::class);
        $this->connection = $container->get(Connection::class);
        $queue = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $this->queue = $queue;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAKnownTokenStoresFileAndRowAndQueuesProcessing(): void
    {
        $raw = InboundMailKit::mail($this->kit->address);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook));

        $row = $this->onlyRow();
        self::assertSame($this->kit->tenant->toString(), $row['tenant_id']);
        self::assertSame($this->kit->feedId, $row['bank_feed_id']);
        self::assertSame('webhook', $row['channel']);
        self::assertSame(hash('sha256', InboundMailKit::MESSAGE_ID), $row['dedup_key']);
        self::assertSame(hash('sha256', $raw), $row['raw_sha256']);
        self::assertSame(\strlen($raw), Scalars::int($row['size']));
        self::assertSame('received', $row['status']);
        self::assertSame('info@bank.example.com', $row['from_address']);
        self::assertSame('Payment notification', $row['subject']);
        self::assertSame(0, Scalars::int($row['attempts']));
        self::assertNull($row['trust']);
        self::assertNull($row['processed_at']);
        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($row['received_at']));

        $id = Scalars::text($row['id']);
        self::assertSame($this->kit->tenant->toString().'/'.$id.'.eml', $row['raw_key']);
        $stored = self::getContainer()->get(InboundFiles::class)->read($this->kit->tenant->toString(), Scalars::text($row['raw_key']), hash('sha256', $raw));
        self::assertSame($raw, $stored);

        $sent = $this->queue->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ProcessInboundMessage::class, $message);
        self::assertSame($id, $message->messageId);
        $stamp = $sent[0]->last(TenantStamp::class);
        self::assertInstanceOf(TenantStamp::class, $stamp);
        self::assertSame($this->kit->tenant->toString(), $stamp->tenantId);

        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    public function testTheTokenIsFoundInDeliveredToWhenToIsTheOwnersMailbox(): void
    {
        // A firm's own mailbox forwards the bank's mail: To still names the firm.
        $raw = InboundMailKit::mail('Example Firm <accounts@firm.example.com>', headers: [
            'Delivered-To' => $this->kit->address,
            'X-Forwarded-To' => 'someone@example.com',
        ]);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));

        $row = $this->onlyRow();
        self::assertSame($this->kit->feedId, $row['bank_feed_id']);
        self::assertSame('imap', $row['channel']);
    }

    public function testTheTokenIsFoundAmongSeveralRecipientsAndInAnyCase(): void
    {
        $raw = InboundMailKit::mail('accounts@firm.example.com, "Bank feed" <'.strtoupper($this->kit->address).'>');

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));
        self::assertSame($this->kit->feedId, $this->onlyRow()['bank_feed_id']);
    }

    public function testTheRecipientOfTheWebhookComesFirst(): void
    {
        $raw = InboundMailKit::mail('accounts@firm.example.com', headers: ['Delivered-To' => $this->kit->unknownAddress()]);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook, $this->kit->address));
        self::assertSame($this->kit->feedId, $this->onlyRow()['bank_feed_id']);
    }

    public function testAnUnknownTokenStoresNothing(): void
    {
        $raw = InboundMailKit::mail($this->kit->unknownAddress());

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive($raw, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(InboundMailKit::mail('accounts@firm.example.com'), InboundChannel::Imap));

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->queue->getSent());
        self::assertSame([], $this->storedFiles());
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    public function testTheSameMessageTwiceIsOneRow(): void
    {
        $fetched = InboundMailKit::mail($this->kit->address);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($fetched, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($fetched, InboundChannel::Webhook));

        self::assertSame(1, $this->rowCount());
        self::assertSame('imap', $this->onlyRow()['channel']);
        self::assertCount(1, $this->queue->getSent());
        self::assertCount(1, $this->storedFiles());
    }

    /**
     * A Message-ID is the sender's word: anyone can send junk with the one of a
     * notice the bank is about to send. A copy with the same Message-ID and other
     * bytes is kept under the hash of its bytes; should both carry the same
     * movement, the movement of the second is a possible duplicate.
     */
    public function testTheSameMessageIdWithOtherBytesIsKeptUnderItsBytes(): void
    {
        $first = InboundMailKit::mail($this->kit->address, body: "Junk that took the bank's Message-ID.\r\n");
        // The webhook copy passed one more server, so its bytes differ.
        $second = InboundMailKit::mail($this->kit->address, headers: ['Received' => 'from relay.example.com by mx.example.com']);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($first, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($second, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($second, InboundChannel::Imap));

        $keys = $this->connection->fetchFirstColumn('SELECT dedup_key FROM inbound_message ORDER BY dedup_key');
        $expected = [hash('sha256', InboundMailKit::MESSAGE_ID), hash('sha256', $second)];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertCount(2, $this->queue->getSent());
    }

    public function testNoMessageIdFallsBackToTheRawHash(): void
    {
        $raw = InboundMailKit::mail($this->kit->address, null);
        $other = InboundMailKit::mail($this->kit->address, null, body: "Another payment.\r\n");

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($raw, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($other, InboundChannel::Imap));

        $keys = $this->connection->fetchFirstColumn('SELECT dedup_key FROM inbound_message ORDER BY dedup_key');
        $expected = [hash('sha256', $raw), hash('sha256', $other)];
        sort($expected);
        self::assertSame($expected, $keys);
    }

    public function testTheSameMessageInAnotherTenantIsItsOwnRow(): void
    {
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive(InboundMailKit::mail($this->kit->address), InboundChannel::Imap));
        // The same dedup key in another tenant does not hide the message.
        $this->connection->executeStatement('UPDATE inbound_message SET tenant_id = :other', ['other' => TenantId::generate()->toString()]);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive(InboundMailKit::mail($this->kit->address), InboundChannel::Imap));
        self::assertSame(2, $this->rowCount());
    }

    public function testAboveTenMebibytesIsTooLarge(): void
    {
        $limit = 10 * 1024 * 1024;
        $header = InboundMailKit::mail($this->kit->address, body: '');
        $atLimit = $header.str_repeat('a', $limit - \strlen($header));

        self::assertSame(ReceiveOutcome::TooLarge, $this->receive->receive($atLimit.'a', InboundChannel::Webhook));
        self::assertSame(0, $this->rowCount());
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($atLimit, InboundChannel::Webhook));
    }

    public function testBytesThatAreNoMailAreUnreadable(): void
    {
        self::assertSame(ReceiveOutcome::Unreadable, $this->receive->receive("not a mail\r\n", InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Unreadable, $this->receive->receive('', InboundChannel::Imap));
        self::assertSame(0, $this->rowCount());
    }

    public function testTheFeedRecordsLastReceived(): void
    {
        self::assertNull($this->connection->fetchOne('SELECT last_received_at FROM bank_feed'));

        $this->receive->receive(InboundMailKit::mail($this->kit->address), InboundChannel::Imap);

        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($this->connection->fetchOne('SELECT last_received_at FROM bank_feed')));
    }

    public function testATenantActiveBeforeIsActiveAfter(): void
    {
        $context = self::getContainer()->get(TenantContext::class);
        $own = TenantId::generate();
        $context->switchTo($own);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive(InboundMailKit::mail($this->kit->address), InboundChannel::Imap));

        self::assertTrue($context->current()->equals($own));
    }

    public function testATenantActiveBeforeIsActiveAgainWhenStoringFails(): void
    {
        $context = self::getContainer()->get(TenantContext::class);
        $own = TenantId::generate();
        $context->switchTo($own);
        FailingInboundFiles::failOn('fail-storage');

        try {
            $this->receive->receive(InboundMailKit::mail($this->kit->address, headers: ['X-Test' => 'fail-storage']), InboundChannel::Imap);
            self::fail('A full disk fails the store.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The disk is full.', $failure->getMessage());
        } finally {
            FailingInboundFiles::failOn(null);
        }

        self::assertTrue($context->current()->equals($own), 'Not the tenant of the address.');
        self::assertSame(0, $this->rowCount());
    }

    public function testAFailedStoreLeavesNoFileBehind(): void
    {
        // The duplicate check still works; only the insert, after the file is written, fails.
        $this->connection->executeStatement('ALTER TABLE inbound_message DROP COLUMN subject');

        try {
            $this->receive->receive(InboundMailKit::mail($this->kit->address), InboundChannel::Imap);
            self::fail('Storing without the column must fail.');
        } catch (\Throwable $failure) {
            self::assertStringNotContainsString('must fail', $failure->getMessage());
        }

        self::assertSame([], $this->storedFiles());
        self::assertSame([], $this->queue->getSent());
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM inbound_message');
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function rowCount(): int
    {
        return Scalars::int($this->connection->fetchOne('SELECT COUNT(*) FROM inbound_message'));
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $directory = self::getContainer()->getParameter('app.inbound_mail_dir').'/'.$this->kit->tenant->toString();
        $names = is_dir($directory) ? scandir($directory) : false;

        return false === $names ? [] : array_values(array_diff($names, ['.', '..']));
    }
}
