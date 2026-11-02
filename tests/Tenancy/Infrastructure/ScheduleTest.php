<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Tenancy\Application\Schedule\Claim;
use App\Tenancy\Application\Schedule\RunScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tenancy\Infrastructure\Schedule\ForesiSchedule;
use App\Tenancy\Infrastructure\Schedule\ScheduleTick;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Tenancy\Fixtures\TestNote;
use App\Tests\Tenancy\Support\CountingJob;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class ScheduleTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private Connection $connection;
    private TenantContext $context;
    private RunMessages $worker;
    private InMemoryTransport $queue;
    private TenantId $first;
    private TenantId $second;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-01 08:00:00');
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->connection = $container->get(Connection::class);
        $this->context = $container->get(TenantContext::class);
        CountingJob::reset();
        $queue = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $this->queue = $queue;
        $this->worker = new RunMessages($queue, $container->get(MessageBusInterface::class));

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->first = $this->tenant('Example One', 'note of one');
        $this->second = $this->tenant('Example Two', 'note of two');
        $this->context->reset();
    }

    public function testAJobRunsOncePerTenantPerPeriod(): void
    {
        $this->tick();
        $this->tick();

        self::assertCount(2, CountingJob::runs());
        self::assertSame([], $this->worker->pending());
        self::assertEqualsCanonicalizing([$this->first->toString(), $this->second->toString()], array_column(CountingJob::runs(), 'tenant'));
        foreach ([$this->first, $this->second] as $tenant) {
            $row = $this->row($tenant);
            self::assertSame('2026-11-01', $row['last_period']);
            self::assertNotNull($row['finished_at']);
            self::assertNull($row['last_error']);
        }
    }

    public function testANewPeriodRunsAgainAndPassesTheLastOne(): void
    {
        $this->tick();
        self::mockTime('2026-11-02 00:05:00');
        $this->tick();

        $runs = CountingJob::runs();
        self::assertCount(4, $runs);
        self::assertSame([null, null, '2026-11-01', '2026-11-01'], array_column($runs, 'lastPeriod'));
        self::assertSame('2026-11-02', $this->row($this->first)['last_period']);
    }

    public function testAFailedRunGivesItsPeriodBackAndRetries(): void
    {
        CountingJob::failNext(1);
        $this->tick();

        $first = CountingJob::runs();
        self::assertCount(1, $first);
        $failed = $first[0]['tenant'] === $this->first->toString() ? $this->second : $this->first;
        $row = $this->row($failed);
        self::assertNull($row['last_period']);
        self::assertNotNull($row['finished_at']);
        self::assertIsString($row['last_error']);
        self::assertStringContainsString('Planned failure.', $row['last_error']);

        self::mockTime('2026-11-01 08:05:00');
        $this->tick();

        $runs = CountingJob::runs();
        self::assertCount(2, $runs);
        self::assertSame($failed->toString(), $runs[1]['tenant']);
        $row = $this->row($failed);
        self::assertSame('2026-11-01', $row['last_period']);
        self::assertNull($row['last_error']);
    }

    public function testACrashedClaimIsReclaimedAfterAnHour(): void
    {
        $this->context->set($this->first);
        self::assertInstanceOf(Claim::class, $this->runs()->claim(CountingJob::NAME, '2026-11-01'));
        $this->context->reset();

        self::mockTime('2026-11-01 08:59:00');
        $this->tick();
        self::assertSame([$this->second->toString()], array_column(CountingJob::runs(), 'tenant'));
        $this->context->set($this->first);
        self::assertNull($this->runs()->claim(CountingJob::NAME, '2026-11-01'));
        $this->context->reset();

        self::mockTime('2026-11-01 09:01:00');
        $this->tick();
        self::assertSame([$this->second->toString(), $this->first->toString()], array_column(CountingJob::runs(), 'tenant'));
        $row = $this->row($this->first);
        self::assertSame('2026-11-01', $row['last_period']);
        self::assertNotNull($row['finished_at']);
    }

    public function testAJobSeesOnlyItsTenant(): void
    {
        $this->tick();

        $seen = [];
        foreach (CountingJob::runs() as $run) {
            $seen[$run['tenant']] = $run['notes'];
        }
        self::assertSame(['note of one'], $seen[$this->first->toString()]);
        self::assertSame(['note of two'], $seen[$this->second->toString()]);
        self::assertFalse($this->context->has());
    }

    public function testTwoWorkersClaimOnce(): void
    {
        $this->context->set($this->first);

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
        self::assertFalse($this->stored($this->second));
    }

    public function testATickDoesNotQueueAPeriodTwice(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new ScheduleTick());
        self::mockTime('2026-11-01 08:05:00');
        $bus->dispatch(new ScheduleTick());
        self::assertCount(2, $this->countingSent(), 'A busy worker is not handed the period again.');
        self::assertSame('2026-11-01', $this->stored($this->first)['queued_period'] ?? null);

        self::mockTime('2026-11-01 09:01:00');
        $bus->dispatch(new ScheduleTick());
        self::assertCount(4, $this->countingSent(), 'A queued message lost for an hour is sent again.');

        $this->worker->run();
        self::assertCount(2, CountingJob::runs(), 'The second copy finds the period done.');
        $row = $this->stored($this->first);
        self::assertIsArray($row);
        self::assertNull($row['queued_period']);
    }

    public function testALateFinishOfAReclaimedRunChangesNothing(): void
    {
        $this->context->set($this->first);
        $crashed = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $crashed);

        self::mockTime('2026-11-01 09:01:00');
        $again = $this->runs()->claim(CountingJob::NAME, '2026-11-01');
        self::assertInstanceOf(Claim::class, $again);

        $this->runs()->finish($crashed);
        $this->runs()->giveBack($crashed, 'late');
        $row = $this->row($this->first);
        self::assertNull($row['finished_at']);
        self::assertNull($row['last_period']);
        self::assertNull($row['last_error']);

        $this->runs()->finish($again);
        self::assertSame('2026-11-01', $this->row($this->first)['last_period']);
    }

    public function testTimesAreStoredInUtc(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-01 09:00:00.750', new \DateTimeZone('Europe/Prague')));
        $this->context->set($this->first);

        $claim = $this->runs()->claim(CountingJob::NAME, '2026-11-01');

        self::assertInstanceOf(Claim::class, $claim);
        self::assertSame('2026-11-01 08:00:00.000000 UTC', $claim->startedAt->format('Y-m-d H:i:s.u T'));
        self::assertSame('2026-11-01 08:00:00', $this->stored($this->first)['started_at'] ?? null);
    }

    public function testPeriodsCompareByteByByte(): void
    {
        $this->context->set($this->first);
        $claim = $this->runs()->claim(CountingJob::NAME, 'B');
        self::assertInstanceOf(Claim::class, $claim);
        $this->runs()->finish($claim);

        // Byte order puts "B" before "a"; a locale's order would not.
        self::assertInstanceOf(Claim::class, $this->runs()->claim(CountingJob::NAME, 'a'));
    }

    public function testTheTickSendsOneStampedMessagePerTenantToTheQueue(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScheduleTick());

        $sent = $this->countingSent();
        self::assertCount(2, $sent);
        $tenants = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RunScheduledJob::class, $message);
            self::assertSame(CountingJob::NAME, $message->job);
            self::assertSame('2026-11-01', $message->period);
            $stamp = $envelope->last(TenantStamp::class);
            self::assertInstanceOf(TenantStamp::class, $stamp);
            $tenants[] = $stamp->tenantId;
        }
        self::assertEqualsCanonicalizing([$this->first->toString(), $this->second->toString()], $tenants);
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
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function countingSent(): array
    {
        return array_values(array_filter([...$this->queue->getSent()], static fn (\Symfony\Component\Messenger\Envelope $envelope): bool => !$envelope->getMessage() instanceof RunScheduledJob || CountingJob::NAME === $envelope->getMessage()->job));
    }

    private function runs(): ScheduledJobRuns
    {
        return self::getContainer()->get(ScheduledJobRuns::class);
    }

    private function tenant(string $name, string $note): TenantId
    {
        $id = TenantId::generate();
        $this->em->persist(new Tenant($id, $name, new \DateTimeImmutable('2026-10-01')));
        $this->em->flush();
        $this->context->switchTo($id);
        $this->em->persist(new TestNote($note));
        $this->em->flush();

        return $id;
    }

    /**
     * @return array{last_period: ?string, finished_at: ?string, last_error: ?string}
     */
    private function row(TenantId $tenant): array
    {
        $row = $this->stored($tenant);
        self::assertIsArray($row, 'The job has a run record in the tenant.');

        return ['last_period' => $this->text($row['last_period']), 'finished_at' => $this->text($row['finished_at']), 'last_error' => $this->text($row['last_error'])];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function stored(TenantId $tenant): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT last_period, started_at, finished_at, last_error, queued_period FROM scheduled_job_run WHERE tenant_id = :tenant AND job = :job',
            ['tenant' => $tenant->toString(), 'job' => CountingJob::NAME],
        );
    }

    private function text(mixed $value): ?string
    {
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }
}
