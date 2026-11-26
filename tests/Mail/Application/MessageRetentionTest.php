<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\PurgeReleasedMessages;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Foresi\Tests\Support\Scalars;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * A consumer releases a discarded message for a date; the daily task removes
 * the row and the raw file once the date has passed, and never anything else.
 */
final class MessageRetentionTest extends KernelTestCase
{
    private MailKit $kit;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->kit = MailKit::seed($container->get(EntityManagerInterface::class), $container->get(AddressIssuer::class));
        $this->connection = $container->get(Connection::class);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAReleasedMessageIsPurgedAfterItsDate(): void
    {
        $kept = $this->store('kept@mail.example.com');
        $released = $this->store('released@mail.example.com');
        $this->retention()->release($released, new \DateTimeImmutable('2026-11-21 00:00:00', new \DateTimeZone('UTC')));

        $this->purge();
        self::assertSame(2, $this->rows(), 'Before its date it stays.');
        self::assertTrue($this->rawFileExists($released));

        Clock::set(new MockClock('2026-11-21 00:00:01', 'UTC'));
        $this->purge();

        self::assertSame([$kept], $this->ids());
        self::assertFalse($this->rawFileExists($released));
        self::assertTrue($this->rawFileExists($kept), 'A message never released stays.');
    }

    public function testAKeptMessageIsNotPurgedAndAnUnknownOneIsIgnored(): void
    {
        $id = $this->store('restored@mail.example.com');
        $this->retention()->release($id, new \DateTimeImmutable('2026-11-01'));
        $this->retention()->keep($id);
        $this->retention()->release('00000000-0000-4000-8000-000000000000', new \DateTimeImmutable('2026-11-01'));
        $this->retention()->keep('00000000-0000-4000-8000-000000000000');

        $this->purge();

        self::assertSame([$id], $this->ids());
        self::assertNull($this->connection->fetchOne('SELECT purge_after FROM mail_message'));
        self::assertTrue($this->rawFileExists($id));
    }

    public function testAMessageAnotherTableStillRefersToKeepsItsRowAndFile(): void
    {
        $referenced = $this->store('referenced@mail.example.com');
        $free = $this->store('free@mail.example.com');
        $sqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        $uuid = $sqlite ? 'VARCHAR(36)' : 'UUID';
        // SQLite checks foreign keys only when asked to; PostgreSQL always does.
        if ($sqlite) {
            $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        }
        $this->connection->executeStatement('CREATE TABLE test_mail_reference (id '.$uuid.' PRIMARY KEY, mail_message_id '.$uuid.' NOT NULL REFERENCES mail_message (id))');
        $this->connection->executeStatement('INSERT INTO test_mail_reference (id, mail_message_id) VALUES (:id, :message)', ['id' => '00000000-0000-4000-8000-000000000001', 'message' => $referenced]);
        foreach ([$referenced, $free] as $id) {
            $this->retention()->release($id, new \DateTimeImmutable('2026-11-01'));
        }

        try {
            $this->purge();

            self::assertSame([$referenced], $this->ids());
            self::assertTrue($this->rawFileExists($referenced));
            self::assertFalse($this->rawFileExists($free));
        } finally {
            $this->connection->executeStatement('DROP TABLE test_mail_reference');
            if ($sqlite) {
                $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
            }
        }
    }

    private function store(string $messageId): string
    {
        $outcome = self::getContainer()->get(ReceiveMail::class)->receive(MailKit::mail($this->kit->address, $messageId), InboundChannel::Webhook);
        self::assertSame(ReceiveOutcome::Stored, $outcome);

        return Scalars::text($this->connection->fetchOne('SELECT id FROM mail_message WHERE message_id_header = :id', ['id' => $messageId]));
    }

    private function purge(): void
    {
        self::getContainer()->get(PurgeReleasedMessages::class)->run();
    }

    private function retention(): MessageRetention
    {
        return self::getContainer()->get(MessageRetention::class);
    }

    private function rows(): int
    {
        return Scalars::int($this->connection->fetchOne('SELECT COUNT(*) FROM mail_message'));
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        return array_map(Scalars::text(...), $this->connection->fetchFirstColumn('SELECT id FROM mail_message ORDER BY id'));
    }

    private function rawFileExists(string $id): bool
    {
        return is_file(Scalars::text(self::getContainer()->getParameter('app.inbound_mail_dir')).'/'.$id.'.eml');
    }
}
