<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure\Health;

use App\Tenancy\Application\Schedule\Heartbeats;
use App\Tenancy\Infrastructure\Health\HealthCheck;
use App\Tenancy\Infrastructure\Health\HealthStatus;
use App\Tenancy\Infrastructure\Schedule\DoctrineHeartbeats;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Each check of the health run: down without the database, without a worker beat
 * within three minutes or a tick within fifteen; degraded with failed messages
 * or a nearly full data disk; the installation as its worst check.
 */
final class HealthCheckTest extends KernelTestCase
{
    private const string NOW = '2026-11-06 10:00:00';

    private Connection $connection;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->clock = new MockClock(self::NOW, 'UTC');
    }

    public function testFreshBeatsAndAnEmptyFailedQueueAreOk(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 180);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 900);

        $health = $this->check($this->counting(0))->run();

        self::assertSame(HealthStatus::Ok, $health->status());
        self::assertSame([
            'database' => ['status' => HealthStatus::Ok, 'value' => null],
            'worker' => ['status' => HealthStatus::Ok, 'value' => 180],
            'schedule' => ['status' => HealthStatus::Ok, 'value' => 900],
            'failed_messages' => ['status' => HealthStatus::Ok, 'value' => 0],
            'disk_free_percent' => $health->checks['disk_free_percent'],
        ], $health->checks);
        self::assertSame(HealthStatus::Ok, $health->checks['disk_free_percent']['status']);
        self::assertIsInt($health->checks['disk_free_percent']['value']);
    }

    public function testAWorkerSilentForMoreThanThreeMinutesIsDown(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 181);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 10);

        $health = $this->check($this->counting(0))->run();

        self::assertSame(['status' => HealthStatus::Down, 'value' => 181], $health->checks['worker']);
        self::assertSame(HealthStatus::Down, $health->status());
    }

    public function testNoTickForMoreThanFifteenMinutesIsDown(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 10);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 901);

        $health = $this->check($this->counting(0))->run();

        self::assertSame(['status' => HealthStatus::Down, 'value' => 901], $health->checks['schedule']);
        self::assertSame(HealthStatus::Down, $health->status());
    }

    public function testAWorkerThatNeverBeatIsDown(): void
    {
        $health = $this->check($this->counting(0))->run();

        self::assertSame(['status' => HealthStatus::Down, 'value' => null], $health->checks['worker']);
        self::assertSame(['status' => HealthStatus::Down, 'value' => null], $health->checks['schedule']);
        self::assertSame(HealthStatus::Down, $health->status());
    }

    public function testFailedMessagesDegradeWithTheirCountOnly(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 0);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 0);

        $health = $this->check($this->counting(3))->run();

        self::assertSame(['status' => HealthStatus::Degraded, 'value' => 3], $health->checks['failed_messages']);
        self::assertSame(HealthStatus::Degraded, $health->status());
    }

    public function testAFailedQueueThatCannotBeCountedDegrades(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 0);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 0);

        $health = $this->check($this->counting(null))->run();

        self::assertSame(['status' => HealthStatus::Degraded, 'value' => null], $health->checks['failed_messages']);
    }

    public function testATransportThatKeepsNoCountIsOk(): void
    {
        $health = $this->check(new InMemoryTransport())->run();

        self::assertSame(['status' => HealthStatus::Ok, 'value' => null], $health->checks['failed_messages']);
    }

    public function testADiskWithLessThanTheMinimumFreeDegrades(): void
    {
        $this->beatSecondsAgo(Heartbeats::WORKER, 0);
        $this->beatSecondsAgo(Heartbeats::SCHEDULE, 0);
        $free = $this->check($this->counting(0))->run()->checks['disk_free_percent']['value'];
        self::assertIsInt($free);

        // A data directory not created yet is measured on its nearest existing parent.
        $health = $this->check($this->counting(0), sys_get_temp_dir().'/foresi-health-missing/share', $free + 1)->run();

        self::assertSame(HealthStatus::Degraded, $health->checks['disk_free_percent']['status']);
        self::assertSame(HealthStatus::Degraded, $health->status());
        self::assertSame(HealthStatus::Ok, $this->check($this->counting(0), sys_get_temp_dir(), $free)->run()->checks['disk_free_percent']['status']);
    }

    public function testNoDatabaseIsDown(): void
    {
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir().'/foresi-health-missing/dir/none.db']);

        $health = new HealthCheck($broken, new DoctrineHeartbeats($broken, $this->clock), $this->counting(0), $this->clock, sys_get_temp_dir(), 0)->run();

        $down = ['status' => HealthStatus::Down, 'value' => null];
        self::assertSame($down, $health->checks['database']);
        self::assertSame($down, $health->checks['worker']);
        self::assertSame($down, $health->checks['schedule']);
        self::assertSame(HealthStatus::Down, $health->status());
    }

    private function check(TransportInterface $failed, ?string $dataDir = null, int $minFreePercent = 0): HealthCheck
    {
        return new HealthCheck($this->connection, new DoctrineHeartbeats($this->connection, $this->clock), $failed, $this->clock, $dataDir ?? sys_get_temp_dir(), $minFreePercent);
    }

    private function beatSecondsAgo(string $name, int $seconds): void
    {
        new DoctrineHeartbeats($this->connection, new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))->modify(\sprintf('-%d seconds', $seconds))))->beat($name);
    }

    /**
     * A failed transport holding a number of messages, or failing to count them.
     */
    private function counting(?int $count): TransportInterface
    {
        return new readonly class($count) implements TransportInterface, MessageCountAwareInterface {
            public function __construct(private ?int $count)
            {
            }

            public function getMessageCount(): int
            {
                return $this->count ?? throw new TransportException('The database is gone.');
            }

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };
    }
}
