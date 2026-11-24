<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\ProcessInboundMessage;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Banking\Support\InboundMailKit;
use Foresi\Tests\Banking\Support\InboundTestCase;
use Foresi\Tests\Banking\Support\Scalars;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Mail hands every stored message of a feed's address to the bank feed: it
 * becomes a row of the bank mail with the same id, waiting for processing and
 * queued for it, and the feed records when mail last came. The address of an
 * archived account takes in nothing.
 */
final class BankFeedPurposeTest extends InboundTestCase
{
    public function testAStoredMailBecomesAWaitingBankMailOfTheSameIdAndIsQueued(): void
    {
        $raw = InboundMailKit::mail($this->address);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook));

        $mailId = Scalars::text($this->connection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]));
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM inbound_message');
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame([$mailId, $mailId, $this->feedId(), 'received', 0], [$row['id'], $row['mail_message_id'], $row['bank_feed_id'], $row['status'], Scalars::int($row['attempts'])]);
        self::assertSame([null, null, null, null, null, null, null], [$row['trust'], $row['trust_basis'], $row['parser_id'], $row['parser_version'], $row['failure'], $row['failure_detail'], $row['processed_at']]);

        $sent = $this->queue()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ProcessInboundMessage::class, $message);
        self::assertSame($mailId, $message->messageId);
    }

    public function testTheFeedRecordsWhenMailLastCame(): void
    {
        self::assertNull($this->connection()->fetchOne('SELECT last_received_at FROM bank_feed'));

        $this->receive->receive(InboundMailKit::mail($this->address), InboundChannel::Imap);

        self::assertStringStartsWith('2026-11-20', Scalars::text($this->connection()->fetchOne('SELECT last_received_at FROM bank_feed')));
    }

    public function testTheAddressOfAnArchivedAccountKeepsNothing(): void
    {
        $this->account->archive(new \DateTimeImmutable('2026-11-19'));
        $this->em->flush();
        $marker = bin2hex(random_bytes(8));

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(InboundMailKit::mail($this->address, headers: ['X-Test-Run' => $marker]), InboundChannel::Webhook));

        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_message')));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM inbound_message')));
        self::assertSame([], $this->queue()->getSent());
        self::assertNull($this->connection()->fetchOne('SELECT last_received_at FROM bank_feed'));
        $directory = Scalars::text(self::getContainer()->getParameter('app.inbound_mail_dir'));
        $files = is_dir($directory) ? scandir($directory) : false;
        $files = false === $files ? [] : $files;
        self::assertSame([], array_values(array_filter($files, static fn (string $name): bool => str_contains((string) @file_get_contents($directory.'/'.$name), $marker))));
    }

    private function feedId(): string
    {
        return Scalars::text($this->connection()->fetchOne('SELECT id FROM bank_feed'));
    }

    private function queue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return $queue;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }
}
