<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Instance\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Instance\Application\Schedule\Claim;
use Foresi\Instance\Application\Schedule\RunScheduledJob;
use Foresi\Instance\Application\Schedule\ScheduledJobRuns;
use Foresi\Instance\Infrastructure\Schedule\ForesiSchedule;
use Foresi\Instance\Infrastructure\Schedule\ScheduleTick;
use Foresi\Tests\Import\Support\RunMessages;
use Foresi\Tests\Instance\Support\CountingJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class ScheduleTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    private Connection $connection;
    private RunMessages $worker;
    private InMemoryTransport $queue;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-01 08:00:00');
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $this->connection = $container->get(Connection::class);
        CountingJob::reset();
        $queue = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $this->queue = $queue;
        $this->worker = new RunMessages($queue, $container->get(MessageBusInterface::class));

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testAJobRunsOncePerPeriod(): void
    {
        $this->tick();
        $this->tick();

        self::assertCount(1, CountingJob::runs());
        self::assertSame([], $this->worker->pending());
        $row = $this->row();
        self::assertSame('2026-11-01', $row['last_period']);
        self::assertNotNull($row['finished_at']);
        self::assertNull($row['last_error']);
    }

    public function testANewPeriodRunsAgainAndPassesTheLastOne(): void
    {
        $this->tick();
        self::mockTime('2026-11-02 00:05:00');
        $this->tick();

        $runs = CountingJob::runs();
        self::assertCount(2, $runs);
        self::assertSame([null, '2026-11-01'], array_column($runs, 'lastPeriod'));
        self::assertSame('2026-11-02', $this->row()['last_period']);
    }

    public function testAFailedRunGivesItsPeriodBackAndRetries(): void
    {
        CountingJob::failNext(1);
        $this->tick();

        self::assertSame([], CountingJob::runs());
        $row = $this->row();
        self::assertNull($row['last_period']);
        self::assertNotNull($row['finished_at']);
        self::assertIsString($row['last_error']);
        self::assertStringContainsString('Planned failure.', $row['last_error']);

        self::mockTime('2026-11-01 08:05:00');
        $this->tick();

        self::assertCount(1, CountingJob::runs());
        $row = $this->row();
        self::assertSame('2026-11-01', $row['last_period']);
        self::assertNull($row['last_error']);
    }

    public function testACrashedClaimIsReclaimedAfterAnHour(): void
    {
        self::assertInstanceOf(Claim::class, $this->runs()->claim(CountingJob::NAME, '2026-11-01'));

        self::mockTime('2026-11-01 08:59:00');
        $this->tick();
        self::assertSame([], CountingJob::runs());
        self::assertNull($this->runs()->claim(CountingJob::NAME, '2026-11-01'));

        self::mockTime('2026-11-01 09:01:00');
        $this->tick();
        self::assertCount(1, CountingJob::runs());
        $row = $this->row();
        self::assertSame('2026-11-01', $row['last_period']);
        self::assertNotNull($row['finished_at']);
    }

    public function testTwoWorkersClaimOnce(): void
    {
        $claim = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $claim);
        self::assertSame(CountingJob::NAME, $claim->job);
        self::assertSame('2026-11-01', $claim->period);
        self::assertNull($claim->lastPeriod);
        self::assertNull($this->runs()->claim(CountingJob::NAME, '2026-11-01'));

        $this->runs()->finish($claim);
        self::assertNull($this->runs()->claim(CountingJob::NAME, '2026-11-01'));
        $next = $this->runs()->claim(CountingJob::NAME, '2026-11-02');
        self::assertInstanceOf(Claim::class, $next);
        self::assertSame('2026-11-01', $next->lastPeriod);
    }

    public function testEachJobHasOneRow(): void
    {
        $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        $this->runs()->claim(CountingJob::NAME, '2026-11-02');
        $this->runs()->queue(CountingJob::NAME, '2026-11-03');

        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM scheduled_job_run WHERE job = :job', ['job' => CountingJob::NAME]));
    }

    public function testATickDoesNotQueueAPeriodTwice(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new ScheduleTick());
        self::mockTime('2026-11-01 08:05:00');
        $bus->dispatch(new ScheduleTick());
        self::assertCount(1, $this->countingSent(), 'A busy worker is not handed the period again.');
        self::assertSame('2026-11-01', $this->stored()['queued_period'] ?? null);

        self::mockTime('2026-11-01 09:01:00');
        $bus->dispatch(new ScheduleTick());
        self::assertCount(2, $this->countingSent(), 'A queued message lost for an hour is sent again.');

        $this->worker->run();
        self::assertCount(1, CountingJob::runs(), 'The second copy finds the period done.');
        $row = $this->stored();
        self::assertIsArray($row);
        self::assertNull($row['queued_period']);
    }

    public function testALateFinishOfAReclaimedRunChangesNothing(): void
    {
        $crashed = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $crashed);

        self::mockTime('2026-11-01 09:01:00');
        $again = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $again);

        $this->runs()->finish($crashed);
        $this->runs()->giveBack($crashed, 'late');
        $row = $this->row();
        self::assertNull($row['finished_at']);
        self::assertNull($row['last_period']);
        self::assertNull($row['last_error']);

        $this->runs()->finish($again);
        self::assertSame('2026-11-01', $this->row()['last_period']);
    }

    public function testTimesAreStoredInUtc(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-01 09:00:00.750', new \DateTimeZone('Europe/Prague')));

        $claim = $this->runs()->claim(CountingJob::NAME, '2026-11-01');

        self::assertInstanceOf(Claim::class, $claim);
        self::assertSame('2026-11-01 08:00:00.000000 UTC', $claim->startedAt->format('Y-m-d H:i:s.u T'));
        self::assertSame('2026-11-01 08:00:00', $this->stored()['started_at'] ?? null);
    }

    public function testPeriodsCompareByteByByte(): void
    {
        $claim = $this->runs()->claim(CountingJob::NAME, 'B');
        self::assertInstanceOf(Claim::class, $claim);
        $this->runs()->finish($claim);

        // Byte order puts "B" before "a"; a locale's order would not.
        self::assertInstanceOf(Claim::class, $this->runs()->claim(CountingJob::NAME, 'a'));
    }

    public function testTheTickSendsOneMessageToTheQueue(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScheduleTick());

        $sent = $this->countingSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RunScheduledJob::class, $message);
        self::assertSame(CountingJob::NAME, $message->job);
        self::assertSame('2026-11-01', $message->period);
    }

    public function testTheTickPassesTheInstanceZone(): void
    {
        // The tests run with APP_TIMEZONE empty and no firm, so the zone is UTC.
        self::assertSame('', self::getContainer()->getParameter('app.timezone'));
        // 23:30 UTC is already 1 November in Prague, but the period is that of UTC.
        self::mockTime('2026-10-31 23:30:00');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScheduleTick());

        $sent = $this->countingSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RunScheduledJob::class, $message);
        self::assertSame('2026-10-31', $message->period);
        self::assertSame(['UTC'], array_values(array_unique(CountingJob::zones())));

        $this->worker->run();
        self::assertSame('2026-10-31', $this->row()['last_period']);
    }

    public function testLastFinishedReadsTheJobsLastFinishedPeriod(): void
    {
        self::assertNull($this->runs()->lastFinished(CountingJob::NAME), 'No run yet.');
        $claim = $this->runs()->claim(CountingJob::NAME, '2026-10-31');
        self::assertInstanceOf(Claim::class, $claim);
        $this->runs()->finish($claim);
        $running = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $running);
        self::assertSame('2026-10-31', $this->runs()->lastFinished(CountingJob::NAME), 'A run in progress has not finished.');
        self::assertNull($this->runs()->lastFinished('another-job'));
    }

    public function testTheScheduleTicksEveryFiveMinutesUnderALock(): void
    {
        $schedule = self::getContainer()->get(ForesiSchedule::class)->getSchedule();

        // The tick comes first; the operator tasks follow it (see OperatorTaskTest).
        $messages = $schedule->getRecurringMessages();
        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
        self::assertSame('every 5 minutes', (string) $trigger);
        self::assertNotNull($schedule->getLock());
        self::assertNotNull($schedule->getState());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }

    public function testTheMailQueueIsItsOwnTransport(): void
    {
        self::assertInstanceOf(InMemoryTransport::class, self::getContainer()->get('messenger.transport.mail'));
    }

    private function tick(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScheduleTick());
        $this->worker->run();
    }

    /**
     * The messages of the counting job: the application's own jobs tick too.
     *
     * @return list<Envelope>
     */
    private function countingSent(): array
    {
        return array_values(array_filter([...$this->queue->getSent()], static fn (Envelope $envelope): bool => !$envelope->getMessage() instanceof RunScheduledJob || CountingJob::NAME === $envelope->getMessage()->job));
    }

    private function runs(): ScheduledJobRuns
    {
        return self::getContainer()->get(ScheduledJobRuns::class);
    }

    /**
     * @return array{last_period: ?string, finished_at: ?string, last_error: ?string}
     */
    private function row(): array
    {
        $row = $this->stored();
        self::assertIsArray($row, 'The job has a run record.');

        return ['last_period' => $this->text($row['last_period']), 'finished_at' => $this->text($row['finished_at']), 'last_error' => $this->text($row['last_error'])];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function stored(): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT last_period, started_at, finished_at, last_error, queued_period FROM scheduled_job_run WHERE job = :job',
            ['job' => CountingJob::NAME],
        );
    }

    private function text(mixed $value): ?string
    {
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }
}
