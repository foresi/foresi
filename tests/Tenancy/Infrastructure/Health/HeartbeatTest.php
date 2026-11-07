<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure\Health;

use App\Tenancy\Application\Schedule\Heartbeats;
use App\Tenancy\Infrastructure\Schedule\ScheduleTick;
use App\Tenancy\Infrastructure\Schedule\ScheduleTickHandler;
use App\Tenancy\Infrastructure\Schedule\WorkerHeartbeatListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * The worker beats when it starts and then once a minute of its loop; the
 * schedule beats on every tick. Both land in the database the web reads.
 */
final class HeartbeatTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testTheWorkerBeatsAtMostOnceAMinute(): void
    {
        $beats = $this->recording();
        $clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        $listener = new WorkerHeartbeatListener($beats, $clock, new NullLogger());

        $listener();
        $clock->sleep(59);
        $listener();
        self::assertSame(1, $beats->count);

        $clock->sleep(1);
        $listener();
        $listener();
        self::assertSame(2, $beats->count);
    }

    public function testABeatThatCannotBeWrittenIsLoggedAndTriedAgainOnTheNextLoop(): void
    {
        $failing = new class implements Heartbeats {
            public int $tries = 0;

            public function beat(string $name): void
            {
                ++$this->tries;

                throw new class('no such table: heartbeat') extends \RuntimeException implements \Doctrine\DBAL\Exception {
                };
            }

            public function last(string $name): ?\DateTimeImmutable
            {
                return null;
            }
        };
        $logger = new BufferingLogger();
        $listener = new WorkerHeartbeatListener($failing, new MockClock('2026-11-06 10:00:00', 'UTC'), $logger);

        $listener();
        $listener();

        self::assertSame(2, $failing->tries, 'A failed beat is not counted as written.');
        /** @var list<array{0: string, 1: string}> $logs */
        $logs = $logger->cleanLogs();
        self::assertCount(2, $logs);
        self::assertSame(['warning', 'The worker heartbeat could not be written.'], [$logs[0][0], $logs[0][1]]);
    }

    public function testAStartingWorkerIsRecorded(): void
    {
        $this->assertTheEventBeats(new WorkerStartedEvent($this->worker()));
    }

    public function testARunningWorkerIsRecorded(): void
    {
        $this->assertTheEventBeats(new WorkerRunningEvent($this->worker(), true));
    }

    public function testATickIsRecorded(): void
    {
        $heartbeats = self::getContainer()->get(Heartbeats::class);

        self::getContainer()->get(ScheduleTickHandler::class)(new ScheduleTick());

        $last = $heartbeats->last(Heartbeats::SCHEDULE);
        self::assertNotNull($last);
        self::assertLessThan(5, abs(time() - $last->getTimestamp()));
        self::assertSame('UTC', $last->getTimezone()->getName());
    }

    private function assertTheEventBeats(object $event): void
    {
        $heartbeats = self::getContainer()->get(Heartbeats::class);
        self::assertNull($heartbeats->last(Heartbeats::WORKER));

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch($event);

        self::assertNotNull($heartbeats->last(Heartbeats::WORKER));
        self::assertNull($heartbeats->last(Heartbeats::SCHEDULE));
    }

    private function worker(): Worker
    {
        return new Worker([], self::getContainer()->get('messenger.default_bus'));
    }

    /**
     * @return Heartbeats&object{count: int}
     */
    private function recording(): Heartbeats
    {
        return new class implements Heartbeats {
            public int $count = 0;

            public function beat(string $name): void
            {
                ++$this->count;
            }

            public function last(string $name): ?\DateTimeImmutable
            {
                return null;
            }
        };
    }
}
