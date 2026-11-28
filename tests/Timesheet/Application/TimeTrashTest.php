<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\PurgeTimeTrashDaily;
use Foresi\Timesheet\Application\PurgeTrashedTime;
use Foresi\Timesheet\Application\TimeEntryRow;
use Foresi\Timesheet\Application\TimeGrouping;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Timesheet\Application\TimeReportFilter;
use Foresi\Timesheet\Application\TrashedTime;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Work\Application\PurgeDeletedTasks;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;

/**
 * The trash of time: deleting an entry puts it there, where it counts nowhere,
 * the author or an owner restores it once its task is back, invoiced time never
 * goes there, and after 30 days it is gone for good.
 */
final class TimeTrashTest extends WorkTestCase
{
    private ManageTime $time;

    private TimeQueries $timeQueries;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->time = self::getContainer()->get(ManageTime::class);
        $this->timeQueries = self::getContainer()->get(TimeQueries::class);
        $this->project = $this->project();
        $this->task = $this->tasks->create($this->project->id(), 'Write the brief');
    }

    public function testDeletedTimeGoesToTheTrashAndCountsNowhere(): void
    {
        $day = new \DateTimeImmutable('2026-11-09');
        $kept = $this->time->log($this->project->id(), $this->task->id(), $day, 60);
        $gone = $this->time->log($this->project->id(), $this->task->id(), $day, 30);
        $this->clock->sleep(60);

        $trashed = $this->time->delete($gone->id());

        self::assertSame(['2026-11-10 09:01', $this->owner->user()->id()], [$trashed->deletedAt()?->format('Y-m-d H:i'), $trashed->deletedBy()]);
        $ofTask = $this->timeQueries->ofTask($this->task->id());
        self::assertNotNull($ofTask);
        self::assertSame([60, [$kept->id()]], [$ofTask->totalMinutes, array_map(static fn (TimeEntryRow $row): string => $row->id, $ofTask->entries)]);
        self::assertSame(60, $this->timeQueries->week($day)?->totalMinutes);
        $filter = new TimeReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        $report = $this->timeQueries->report($filter, TimeGrouping::Project);
        self::assertSame([60, 1], [$report->totalMinutes, $report->entryCount]);
        self::assertCount(1, iterator_to_array($this->timeQueries->reportEntries($filter), false));
        self::assertNull($this->timeQueries->editable($gone->id()));
        // Gone for every command too.
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->delete($gone->id()));
        $this->assertRefused('unknown', fn () => $this->time->edit($gone->id(), null, $day, 15, null, null, '', false));

        self::assertSame(1, $this->timeQueries->trashCount($this->project->id()));
        $trash = $this->timeQueries->trash($this->project->id());
        self::assertSame(
            [[$gone->id(), '2026-11-09', 'Olga Owner', 30, 'Write the brief', 'Olga Owner', true]],
            array_map(static fn (TrashedTime $time): array => [$time->id, $time->workedOn->format('Y-m-d'), $time->personName, $time->minutes, $time->taskTitle, $time->deletedByName, $time->restorable], $trash),
        );
    }

    public function testARunningTimerIsStoppedBeforeItGoesToTheTrash(): void
    {
        $this->signIn($this->member);
        $timer = $this->time->startTimer($this->project->id(), $this->task->id());
        $this->clock->sleep(25 * 60);

        $this->time->delete($timer->id());

        self::assertSame([false, 25], [$timer->isRunning(), $timer->minutes()]);
        self::assertNull($this->timeQueries->running());
        // The person may start another timer at once.
        $this->time->startTimer($this->project->id(), null);
        $this->time->restore($timer->id());
        self::assertSame([false, 25], [$timer->isRunning(), $timer->minutes()]);
    }

    public function testTheAuthorOrAnOwnerRestoresTimeOthersSeeItWithoutTheButton(): void
    {
        $day = new \DateTimeImmutable('2026-11-09');
        $theirs = $this->time->log($this->project->id(), null, $day, 60);
        $this->signIn($this->member);
        $mine = $this->time->log($this->project->id(), null, $day, 30);
        $this->time->delete($mine->id());
        $this->clock->sleep(60);
        $this->signIn($this->owner);
        $this->time->delete($theirs->id());

        $this->signIn($this->member);
        self::assertSame([[$theirs->id(), false], [$mine->id(), true]], array_map(static fn (TrashedTime $time): array => [$time->id, $time->restorable], $this->timeQueries->trash($this->project->id())));
        $this->assertRefused('time_not_yours', fn (): TimeEntry => $this->time->restore($theirs->id()));
        $this->time->restore($mine->id());
        self::assertFalse($mine->isDeleted());
        self::assertSame(30, $this->timeQueries->week($day)?->totalMinutes);
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->restore($mine->id()));
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->restore(Id::generate()));

        $this->signIn($this->owner);
        $this->time->restore($theirs->id());
        self::assertSame(0, $this->timeQueries->trashCount($this->project->id()));

        // A guest sees no time, in the trash or anywhere.
        $this->time->delete($theirs->id());
        $this->signIn($this->guest);
        self::assertSame([[], 0], [$this->timeQueries->trash($this->project->id()), $this->timeQueries->trashCount($this->project->id())]);
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->restore($theirs->id()));
    }

    public function testTimeComesBackOnlyOnceItsTaskIsOutOfTheTrash(): void
    {
        $entry = $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->delete($entry->id());
        $this->tasks->delete($this->task->id());

        $this->assertRefused('time_task_in_trash', fn (): TimeEntry => $this->time->restore($entry->id()));

        $this->tasks->restore($this->task->id());
        $this->time->restore($entry->id());
        self::assertSame(30, $this->timeQueries->ofTask($this->task->id())?->totalMinutes);
    }

    public function testInvoicedTimeNeverGoesToTheTrashAndTrashedTimeIsNeverInvoiced(): void
    {
        $billed = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $billed->invoice(Id::generate(), Id::generate(), Money::ofMinor(100_000, Currency::of('CZK')), 30);
        $this->em->flush();
        $this->assertRefused('time_invoiced', fn (): TimeEntry => $this->time->delete($billed->id()));

        $trashed = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->delete($trashed->id());
        $this->expectException(\DomainException::class);
        $trashed->invoice(Id::generate(), Id::generate(), Money::ofMinor(100_000, Currency::of('CZK')), 30);
    }

    public function testTimeInTheTrashForMoreThan30DaysIsGoneForGoodByTheDailyJob(): void
    {
        $old = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->delete($old->id());
        $this->clock->sleep(10 * 86_400);
        $recent = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 15);
        $this->time->delete($recent->id());
        $live = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 45);
        $this->clock->sleep(21 * 86_400);

        $job = new PurgeTimeTrashDaily(self::getContainer()->get(PurgeTrashedTime::class), $this->clock);
        self::assertSame('time-trash-purge', $job->name());
        $job->run(null);

        $this->em->clear();
        self::assertNull($this->em->find(TimeEntry::class, $old->id()));
        self::assertTrue($this->em->find(TimeEntry::class, $recent->id())?->isDeleted());
        self::assertFalse($this->em->find(TimeEntry::class, $live->id())?->isDeleted());
        self::assertSame(0, self::getContainer()->get(PurgeTrashedTime::class)->run($this->clock->now()));
    }

    public function testTrashedTimeOfAPurgedTaskStaysInTheTrashOnItsProject(): void
    {
        $entry = $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->delete($entry->id());
        $this->tasks->delete($this->task->id());

        self::getContainer()->get(PurgeDeletedTasks::class)->run($this->clock->now()->modify('+31 days'));
        $this->em->clear();

        self::assertSame([[$entry->id(), null]], array_map(static fn (TrashedTime $time): array => [$time->id, $time->taskTitle], $this->timeQueries->trash($this->project->id())));
        $this->time->restore($entry->id());
        self::assertFalse($this->em->find(TimeEntry::class, $entry->id())?->isDeleted());
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (TimeRefused|WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
