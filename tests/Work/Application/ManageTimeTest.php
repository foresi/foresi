<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Domain\Id;
use App\Work\Application\ManageSharing;
use App\Work\Application\ManageTime;
use App\Work\Application\PurgeDeletedTasks;
use App\Work\Application\TimeQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Domain\WorkflowTemplate;

final class ManageTimeTest extends WorkTestCase
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

    public function testATeamMemberLogsTimeOnATaskOrOnTheProjectBillableAsTheProjectSays(): void
    {
        $this->signIn($this->member);
        $day = new \DateTimeImmutable('2026-11-09');
        $onTask = $this->time->log($this->project->id(), $this->task->id(), $day, 90, note: ' Drafted it ');
        $onProject = $this->time->log($this->project->id(), null, $day, 30);
        $billed = $this->time->log($this->project->id(), null, $day, 15, billable: true);

        self::assertSame([$this->task, 90, 'Drafted it', false], [$onTask->task(), $onTask->minutes(), $onTask->note(), $onTask->isBillable()]);
        self::assertSame($this->member, $onTask->membership());
        self::assertSame($this->member->user()->id(), $onTask->createdBy());
        self::assertSame([null, false], [$onProject->task(), $onProject->isBillable()]);
        self::assertTrue($billed->isBillable());

        $this->signIn($this->owner);
        $forClient = $this->projects->create('Shop', $this->client()->id(), WorkflowTemplate::Simple);
        self::assertTrue($forClient->billsTimeByDefault());
        self::assertTrue($this->time->log($forClient->id(), null, $day, 60)->isBillable());
        self::assertFalse($this->time->log($forClient->id(), null, $day, 60, billable: false)->isBillable());

        $this->time->setProjectBillableDefault($this->project->id(), true);
        self::assertTrue($this->time->log($this->project->id(), null, $day, 60)->isBillable());
    }

    public function testTimeIsLoggedAsMinutesOrAsAStartAndAnEnd(): void
    {
        $day = new \DateTimeImmutable('2026-11-09');
        $start = new \DateTimeImmutable('2026-11-09 13:00');
        $span = $this->time->log($this->project->id(), null, $day, null, $start, $start->modify('+1 hour 15 minutes'));
        self::assertSame([75, $start], [$span->minutes(), $span->startedAt()]);

        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, null));
        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 0));
        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 1441));
        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 30, $start, $start->modify('+30 minutes')));
        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, null, $start));
        $this->assertRefused('duration', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, null, $start, $start));
        $this->assertRefused('time_note', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 30, note: str_repeat('a', TimeEntry::MAX_NOTE_LENGTH + 1)));
    }

    public function testATaskOfAnotherProjectIsRefused(): void
    {
        $other = $this->projects->create('Shop', null, WorkflowTemplate::Simple);

        $this->assertRefused('time_task', fn (): TimeEntry => $this->time->log($other->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30));
        $this->assertRefused('time_task', fn (): TimeEntry => $this->time->startTimer($other->id(), $this->task->id()));
        $entry = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $foreign = $this->tasks->create($other->id(), 'Elsewhere');
        $this->assertRefused('time_task', fn () => $this->time->edit($entry->id(), $foreign->id(), new \DateTimeImmutable('2026-11-09'), 30, null, null, '', false));
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->log(Id::generate(), null, new \DateTimeImmutable('2026-11-09'), 30));
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->log($this->project->id(), Id::generate(), new \DateTimeImmutable('2026-11-09'), 30));
    }

    public function testGuestsNeverLogTimeAndTeamMembersOnlyWhereTheySee(): void
    {
        $sharing = self::getContainer()->get(ManageSharing::class);
        $sharing->shareTask($this->task->id(), $this->guest->id());
        $day = new \DateTimeImmutable('2026-11-09');

        $this->signIn($this->guest);
        $this->assertRefused('not_allowed', fn (): TimeEntry => $this->time->log($this->project->id(), $this->task->id(), $day, 30));
        $this->assertRefused('not_allowed', fn (): TimeEntry => $this->time->startTimer($this->project->id(), $this->task->id()));
        $this->assertRefused('not_allowed', fn () => $this->time->setEstimate($this->task->id(), 60));
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 30));

        $this->signIn($this->owner);
        $sharing->changeVisibility($this->project->id(), ProjectVisibility::Chosen);
        $sharing->shareTask($this->task->id(), $this->member->id());
        $this->signIn($this->member);
        // A task shared alone takes time; its project, not open to the member, does not.
        $this->time->log($this->project->id(), $this->task->id(), $day, 30);
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->log($this->project->id(), null, $day, 30));

        $this->signIn($this->owner);
        $sharing->makeTaskPrivate($this->task->id(), true);
        $sharing->unshareTask($this->task->id(), $this->member->id());
        $this->signIn($this->member);
        $this->assertRefused('unknown', fn (): TimeEntry => $this->time->log($this->project->id(), $this->task->id(), $day, 30));
    }

    public function testNoTimeGoesOnAnArchivedProject(): void
    {
        $this->projects->archive($this->project->id());

        $this->assertRefused('archived', fn (): TimeEntry => $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30));
        $this->assertRefused('archived', fn (): TimeEntry => $this->time->startTimer($this->project->id(), null));
    }

    public function testStartingATimerSavesTheRunningOneSoEachPersonRunsOne(): void
    {
        $this->signIn($this->member);
        $first = $this->time->startTimer($this->project->id(), $this->task->id(), 'Brief');
        self::assertSame([true, null, '2026-11-10', '09:00'], [$first->isRunning(), $first->minutes(), $first->workedOn()->format('Y-m-d'), $first->startedAt()?->format('H:i')]);

        $this->clock->sleep(25 * 60);
        $second = $this->time->startTimer($this->project->id(), null);
        self::assertSame([false, 25, '09:25'], [$first->isRunning(), $first->minutes(), $first->endedAt()?->format('H:i')]);
        self::assertTrue($second->isRunning());
        self::assertSame($second->id(), $this->timeQueries->running()?->id);

        // Another person runs a timer of their own.
        $this->signIn($this->owner);
        $theirs = $this->time->startTimer($this->project->id(), $this->task->id());
        self::assertTrue($second->isRunning());
        self::assertSame($theirs->id(), $this->timeQueries->running()?->id);

        $this->signIn($this->member);
        $this->clock->sleep(10);
        $stopped = $this->time->stopTimer();
        self::assertSame($second, $stopped);
        self::assertSame([false, 1], [$stopped->isRunning(), $stopped->minutes()]);
        self::assertNull($this->time->stopTimer());
        self::assertNull($this->timeQueries->running());
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(TimeEntry::class)->count(['runningFor' => $this->owner->id()]));
        self::assertSame(0, $this->em->getRepository(TimeEntry::class)->count(['runningFor' => $this->member->id()]));
    }

    public function testTheDatabaseRefusesASecondRunningTimer(): void
    {
        $this->time->startTimer($this->project->id(), null);
        $second = TimeEntry::started($this->project, null, $this->owner, $this->clock->now(), $this->clock->now(), '', false, $this->owner->user()->id());
        $this->em->persist($second);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testTheTimerDayIsTheDayOfTheOrganisation(): void
    {
        // 23:30 in UTC is half past midnight of the next day in Prague.
        $this->clock->modify('2026-11-10 23:30:00');
        $timer = $this->time->startTimer($this->project->id(), null);

        self::assertSame('2026-11-11', $timer->workedOn()->format('Y-m-d'));
    }

    public function testTheAuthorEditsAndDeletesTheirTimeAndAnOwnerAnyonesUnlessItRuns(): void
    {
        $day = new \DateTimeImmutable('2026-11-09');
        $this->signIn($this->member);
        $mine = $this->time->log($this->project->id(), null, $day, 30);
        $this->time->edit($mine->id(), $this->task->id(), new \DateTimeImmutable('2026-11-08'), 45, null, null, ' Reviewed ', true);
        self::assertSame([$this->task, '2026-11-08', 45, 'Reviewed', true], [$mine->task(), $mine->workedOn()->format('Y-m-d'), $mine->minutes(), $mine->note(), $mine->isBillable()]);

        $this->signIn($this->owner);
        $theirs = $this->time->log($this->project->id(), null, $day, 60);

        $this->signIn($this->member);
        $this->assertRefused('time_not_yours', fn () => $this->time->edit($theirs->id(), null, $day, 30, null, null, '', false));
        $this->assertRefused('time_not_yours', fn () => $this->time->delete($theirs->id()));
        $this->assertRefused('unknown', fn () => $this->time->delete(Id::generate()));
        $timer = $this->time->startTimer($this->project->id(), null);
        $this->assertRefused('timer_running', fn () => $this->time->edit($timer->id(), null, $day, 30, null, null, '', false));

        // An owner keeps anyone's time in order.
        $this->signIn($this->owner);
        $this->time->edit($mine->id(), null, $day, 50, null, null, '', false);
        self::assertSame([null, 50], [$mine->task(), $mine->minutes()]);
        $this->time->delete($mine->id());

        // Deleting a running timer discards it.
        $this->signIn($this->member);
        $this->time->delete($timer->id());
        $this->em->clear();
        self::assertNull($this->em->find(TimeEntry::class, $mine->id()));
        self::assertNull($this->em->find(TimeEntry::class, $timer->id()));
        self::assertNull($this->timeQueries->running());
    }

    public function testAGuestNeitherSeesNorChangesTimeOfWorkHiddenFromThem(): void
    {
        $entry = $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30);

        $this->signIn($this->guest);
        $this->assertRefused('unknown', fn () => $this->time->delete($entry->id()));
        self::assertNull($this->timeQueries->ofTask($this->task->id()));
    }

    public function testAnInvoicedEntryIsLocked(): void
    {
        $entry = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        // Invoicing sets the line; nothing in the work module does yet.
        $this->em->getConnection()->update('time_entry', ['invoice_line_id' => Id::generate()], ['id' => $entry->id()]);
        $this->em->clear();

        $this->assertRefused('time_invoiced', fn () => $this->time->edit($entry->id(), null, new \DateTimeImmutable('2026-11-09'), 60, null, null, '', false));
        $this->assertRefused('time_invoiced', fn () => $this->time->delete($entry->id()));
    }

    public function testAnEstimateIsSetOnATaskTheTeamSees(): void
    {
        $this->signIn($this->member);
        $this->time->setEstimate($this->task->id(), 300);
        self::assertSame(300, $this->task->estimateMinutes());
        $this->assertRefused('estimate', fn () => $this->time->setEstimate($this->task->id(), 0));
        $this->assertRefused('estimate', fn () => $this->time->setEstimate($this->task->id(), Task::MAX_ESTIMATE_MINUTES + 1));
        $this->time->setEstimate($this->task->id(), null);
        self::assertNull($this->task->estimateMinutes());

        $this->signIn($this->guest);
        $this->assertRefused('unknown', fn () => $this->time->setEstimate($this->task->id(), 60));
        $this->assertRefused('unknown', fn () => $this->time->setProjectBillableDefault($this->project->id(), true));
    }

    public function testTimeIsAuditedUnderItsTaskOrItsProject(): void
    {
        $onTask = $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30);
        $onProject = $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->delete($onProject->id());
        $this->time->setEstimate($this->task->id(), 120);
        $trail = self::getContainer()->get(AuditTrail::class);

        $underTask = array_values(array_filter($trail->rootHistory(Task::class, $this->task->id()), static fn (AuditEntry $entry): bool => TimeEntry::class === $entry->entityClass()));
        $underProject = array_values(array_filter($trail->rootHistory(Project::class, $this->project->id()), static fn (AuditEntry $entry): bool => TimeEntry::class === $entry->entityClass()));
        self::assertSame([[$onTask->id(), AuditAction::Created]], array_map(static fn (AuditEntry $entry): array => [$entry->entityId(), $entry->action()], $underTask));
        self::assertSame([[$onProject->id(), AuditAction::Created], [$onProject->id(), AuditAction::Deleted]], array_map(static fn (AuditEntry $entry): array => [$entry->entityId(), $entry->action()], $underProject));
        self::assertContains('work.task.field.estimate', $this->taskHistoryFields());
    }

    public function testTimeOfAPurgedTaskStaysOnItsProject(): void
    {
        $entry = $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 30);
        $this->tasks->delete($this->task->id());
        self::assertNull($this->timeQueries->ofTask($this->task->id()));

        self::getContainer()->get(PurgeDeletedTasks::class)->run($this->clock->now()->modify('+31 days'));
        $this->em->clear();
        $kept = $this->em->find(TimeEntry::class, $entry->id());
        self::assertInstanceOf(TimeEntry::class, $kept);
        self::assertSame([null, 30], [$kept->task(), $kept->minutes()]);
    }

    public function testTheTimeOfATaskAddsUpWhatIsNotRunning(): void
    {
        $this->time->setEstimate($this->task->id(), 300);
        $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-08'), 60);
        $this->signIn($this->member);
        $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 140);
        $this->time->startTimer($this->project->id(), $this->task->id());
        $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);

        $time = $this->timeQueries->ofTask($this->task->id());
        self::assertNotNull($time);
        self::assertSame([200, 300], [$time->totalMinutes, $time->estimateMinutes]);
        self::assertSame(
            [['2026-11-10', null, 'Marek Member', true, true], ['2026-11-09', 140, 'Marek Member', false, true], ['2026-11-08', 60, 'Olga Owner', false, false]],
            array_map(static fn (\App\Work\Application\TimeEntryRow $row): array => [$row->workedOn->format('Y-m-d'), $row->minutes, $row->personName, $row->running, $row->editable], $time->entries),
        );
        self::assertNull($this->timeQueries->ofTask(Id::generate()));
    }

    public function testAWeekShowsEachDayOfThePersonAndOnlyOwnersReadSomeoneElses(): void
    {
        $this->signIn($this->member);
        $this->time->log($this->project->id(), $this->task->id(), new \DateTimeImmutable('2026-11-09'), 60);
        $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-09'), 30);
        $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-15'), 15);
        // The weeks before and after.
        $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-08'), 45);
        $this->time->log($this->project->id(), null, new \DateTimeImmutable('2026-11-16'), 45);

        $week = $this->timeQueries->week(new \DateTimeImmutable('2026-11-12'));
        self::assertNotNull($week);
        self::assertSame(['2026-11-09', $this->member->id(), 105], [$week->monday->format('Y-m-d'), $week->membershipId, $week->totalMinutes]);
        self::assertSame([90, 0, 0, 0, 0, 0, 15], array_map(static fn (\App\Work\Application\TimeDay $day): int => $day->totalMinutes, $week->days));
        self::assertSame(['Write the brief', null], array_map(static fn (\App\Work\Application\TimeEntryRow $row): ?string => $row->taskTitle, $week->days[0]->entries));
        self::assertNull($this->timeQueries->week(new \DateTimeImmutable('2026-11-12'), $this->owner->id()));

        // A Sunday belongs to the week before it.
        self::assertSame('2026-11-09', $this->timeQueries->week(new \DateTimeImmutable('2026-11-15'))?->monday->format('Y-m-d'));

        $this->signIn($this->owner);
        self::assertSame(105, $this->timeQueries->week(new \DateTimeImmutable('2026-11-12'), $this->member->id())?->totalMinutes);
        self::assertSame(0, $this->timeQueries->week(new \DateTimeImmutable('2026-11-12'))?->totalMinutes);
        self::assertNull($this->timeQueries->week(new \DateTimeImmutable('2026-11-12'), Id::generate()));

        // Time on a task the member no longer sees drops out of their week.
        self::getContainer()->get(ManageSharing::class)->makeTaskPrivate($this->task->id(), true);
        $this->signIn($this->member);
        self::assertSame(45, $this->timeQueries->week(new \DateTimeImmutable('2026-11-12'))?->totalMinutes);
    }

    /**
     * @return list<string>
     */
    private function taskHistoryFields(): array
    {
        $fields = [];
        foreach (self::getContainer()->get(\App\Work\Application\TaskHistory::class)->of($this->task->id()) as $entry) {
            array_push($fields, ...$entry->fields);
        }

        return $fields;
    }

    private function client(): Party
    {
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
