<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Domain;

use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\RecurringTaskTemplate;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class RecurringTaskTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project('Retainer', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-01 08:00'));
    }

    public function testAWeeklySlotIsCreatedOnItsMondayAndDueOnItsSunday(): void
    {
        // 30 November 2026 is a Monday.
        $recurring = $this->recurring($this->weekly('2026-11-30'));

        self::assertSame(RecurringTaskState::Active, $recurring->state());
        self::assertSame('2026-11-30', $recurring->nextOccurrence()?->format('Y-m-d'));
        self::assertFalse($recurring->isDue(new \DateTimeImmutable('2026-11-29')));
        self::assertTrue($recurring->isDue(new \DateTimeImmutable('2026-11-30')));
        self::assertSame('2026-12-06', $recurring->dueOn(new \DateTimeImmutable('2026-11-30'))->format('Y-m-d'));
        self::assertSame(['2026-11-30', '2026-12-07', '2026-12-14'], $this->days($recurring->upcoming(3)));
    }

    public function testATaskCanBeCreatedDaysAheadAndDueAfterAnOffset(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'), createDaysBefore: 3, dueDaysAfter: 2);

        self::assertSame('2026-11-27', $recurring->createOn(new \DateTimeImmutable('2026-11-30'))->format('Y-m-d'));
        self::assertFalse($recurring->isDue(new \DateTimeImmutable('2026-11-26')));
        self::assertTrue($recurring->isDue(new \DateTimeImmutable('2026-11-27')));
        self::assertSame('2026-12-02', $recurring->dueOn(new \DateTimeImmutable('2026-11-30'))->format('Y-m-d'));
    }

    public function testTheEndOfAMonthlyPeriodIsTheDayBeforeTheNextDate(): void
    {
        $recurring = $this->recurring(new Recurrence(new \DateTimeImmutable('2027-01-31'), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null));

        self::assertSame('2027-02-27', $recurring->dueOn(new \DateTimeImmutable('2027-01-31'))->format('Y-m-d'));
        self::assertSame('2027-03-30', $recurring->dueOn(new \DateTimeImmutable('2027-02-28'))->format('Y-m-d'));
    }

    public function testAdvancingCountsAndEndsAfterTheCount(): void
    {
        $recurring = $this->recurring(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::AfterCount, null, 2));
        $version = $recurring->version();

        $recurring->advance(new \DateTimeImmutable('2026-11-30'));
        self::assertSame(1, $recurring->createdCount());
        self::assertSame('2026-11-30', $recurring->lastOccurrence()?->format('Y-m-d'));
        self::assertSame('2026-12-07', $recurring->nextOccurrence()?->format('Y-m-d'));
        self::assertSame($version + 1, $recurring->version());

        $recurring->advance(new \DateTimeImmutable('2026-12-07'));
        self::assertSame(RecurringTaskState::Ended, $recurring->state());
        self::assertNull($recurring->nextOccurrence());
        self::assertSame('2026-12-07', $recurring->endedAt()?->format('Y-m-d'));
        self::assertSame([], $recurring->upcoming(5));
    }

    public function testOnlyTheNextDateOfAnActiveOneAdvances(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));

        try {
            $recurring->advance(new \DateTimeImmutable('2026-12-07'));
            self::fail('Not the next date.');
        } catch (\DomainException) {
        }
        $recurring->pause(new \DateTimeImmutable('2026-11-29 10:00'));
        $this->expectException(\DomainException::class);
        $recurring->advance(new \DateTimeImmutable('2026-11-30'));
    }

    public function testASkippedDateIsLeftOutAndDoesNotCount(): void
    {
        $recurring = $this->recurring(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::AfterCount, null, 2));

        $recurring->skip(new \DateTimeImmutable('2026-12-07'));
        $recurring->skip(new \DateTimeImmutable('2026-12-07'));

        self::assertSame(['2026-12-07'], $recurring->skippedOn());
        self::assertSame(['2026-11-30', '2026-12-14'], $this->days($recurring->upcoming(5)));

        $recurring->skip(new \DateTimeImmutable('2026-11-30'));
        self::assertSame('2026-12-14', $recurring->nextOccurrence()?->format('Y-m-d'));
        self::assertSame(['2026-12-14', '2026-12-21'], $this->days($recurring->upcoming(5)));
    }

    public function testOnlyADayItFallsOnAndNotCreatedYetIsSkipped(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->advance(new \DateTimeImmutable('2026-11-30'));

        foreach (['2026-12-08', '2026-11-30'] as $day) {
            try {
                $recurring->skip(new \DateTimeImmutable($day));
                self::fail(\sprintf('%s is skipped.', $day));
            } catch (\DomainException) {
            }
        }
        self::assertSame([], $recurring->skippedOn());
    }

    public function testSkippingTheLastDateEndsIt(): void
    {
        $recurring = $this->recurring(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::OnDate, new \DateTimeImmutable('2026-11-30'), null));

        $recurring->skip(new \DateTimeImmutable('2026-11-30'));

        self::assertSame(RecurringTaskState::Ended, $recurring->state());
        self::assertSame('2026-11-30', $recurring->endedAt()?->format('Y-m-d'));
    }

    public function testResumingSkipsWhatFellInThePause(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->pause(new \DateTimeImmutable('2026-11-29 09:00'));
        $recurring->pause(new \DateTimeImmutable('2026-12-01 09:00'));
        self::assertSame('2026-11-29 09:00', $recurring->pausedAt()?->format('Y-m-d H:i'));
        self::assertFalse($recurring->isDue(new \DateTimeImmutable('2026-12-01')));

        $skipped = $recurring->resume(new \DateTimeImmutable('2026-12-10'));

        self::assertSame(['2026-11-30', '2026-12-07'], $this->days($skipped));
        self::assertSame(RecurringTaskState::Active, $recurring->state());
        self::assertSame('2026-12-14', $recurring->nextOccurrence()?->format('Y-m-d'));
        self::assertNull($recurring->pausedAt());
        self::assertSame([], $recurring->resume(new \DateTimeImmutable('2026-12-11')));
    }

    public function testAnEndedOneStaysEnded(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->end(new \DateTimeImmutable('2026-11-29 10:00'));
        $recurring->end(new \DateTimeImmutable('2026-11-30 10:00'));

        self::assertSame(RecurringTaskState::Ended, $recurring->state());
        self::assertSame('2026-11-29 10:00', $recurring->endedAt()?->format('Y-m-d H:i'));
        self::assertNull($recurring->nextOccurrence());
        foreach ([
            fn () => $recurring->pause(new \DateTimeImmutable()),
            fn (): array => $recurring->resume(new \DateTimeImmutable()),
            fn () => $recurring->skip(new \DateTimeImmutable('2026-12-07')),
            fn () => $recurring->change($this->template($this->weekly('2026-12-07')), new \DateTimeImmutable('2026-11-30')),
        ] as $refused) {
            try {
                $refused();
                self::fail('An ended recurring task changed.');
            } catch (\DomainException) {
            }
        }
    }

    public function testAChangedScheduleNeverRepeatsADateCreated(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->advance(new \DateTimeImmutable('2026-11-30'));

        // Daily from the same first date, edited on the same Monday.
        $recurring->change($this->template(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Day, 1, false, RecurrenceEnd::Never, null, null), title: 'Daily check'), new \DateTimeImmutable('2026-11-30'));

        self::assertSame('Daily check', $recurring->title());
        self::assertSame('2026-12-01', $recurring->nextOccurrence()?->format('Y-m-d'));
    }

    public function testAChangeKeepsADateTheRunHasStillToCatchUpWith(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));

        $recurring->change($this->template($this->weekly('2026-11-30'), title: 'Slot {week}'), new \DateTimeImmutable('2026-12-03'));

        self::assertSame('2026-11-30', $recurring->nextOccurrence()?->format('Y-m-d'));
    }

    public function testAScheduleLeavingNoDateIsRefusedAndKeepsTheOldOne(): void
    {
        $recurring = $this->recurring(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::AfterCount, null, 3));
        $recurring->advance(new \DateTimeImmutable('2026-11-30'));

        try {
            $recurring->change($this->template(new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::AfterCount, null, 1), title: 'Other'), new \DateTimeImmutable('2026-12-01'));
            self::fail('A schedule without a date was taken.');
        } catch (\DomainException) {
        }
        self::assertSame(3, $recurring->recurrence()->endAfter());
        self::assertSame('2026-12-07', $recurring->nextOccurrence()?->format('Y-m-d'));
    }

    public function testTheTemplateHoldsTogether(): void
    {
        $other = new Project('Other', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-01 08:00'));
        foreach ([
            'section of another project' => $this->template($this->weekly('2026-11-30'), section: new Section($other, 'Elsewhere', 0)),
            'created too early' => $this->template($this->weekly('2026-11-30'), createDaysBefore: 91),
            'due too late' => $this->template($this->weekly('2026-11-30'), dueDaysAfter: 366),
            'estimate of nothing' => $this->template($this->weekly('2026-11-30'), estimate: 0),
            'too many subtasks' => $this->template($this->weekly('2026-11-30'), subtasks: array_fill(0, 51, 'Step')),
            'empty subtask' => $this->template($this->weekly('2026-11-30'), subtasks: [' ']),
            'empty title' => $this->template($this->weekly('2026-11-30'), title: ' '),
        ] as $case => $template) {
            try {
                new RecurringTask($this->project, $template, Id::generate(), new \DateTimeImmutable());
                self::fail($case);
            } catch (\DomainException) {
            }
        }
        $recurring = $this->recurring($this->weekly('2026-11-30'), createDaysBefore: 90, dueDaysAfter: 365, estimate: Task::MAX_ESTIMATE_MINUTES, subtasks: array_fill(0, 50, ' Step '));
        self::assertSame('Step', $recurring->subtasks()[0]);
        self::assertCount(50, $recurring->subtasks());
    }

    public function testATaskIsTheOccurrenceOfOneRecurringTaskOnce(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $status = new ProjectStatus($this->project, 'To do', StatusType::Open, 0);
        $task = new Task($this->project, 'Slot', $status, 0, Id::generate(), new \DateTimeImmutable());

        $task->occurrenceOf($recurring, new \DateTimeImmutable('2026-11-30 15:00'));

        self::assertSame($recurring, $task->recurringTask());
        self::assertSame('2026-11-30 00:00', $task->occurrenceOn()?->format('Y-m-d H:i'));
        $this->expectException(\DomainException::class);
        $task->occurrenceOf($recurring, new \DateTimeImmutable('2026-12-07'));
    }

    public function testFailuresAreRecordedAndCleared(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->recordFailure(new \DateTimeImmutable('2026-11-30 01:00'), 'archived');
        self::assertSame('archived', $recurring->lastFailureCode());
        self::assertSame('2026-11-30 01:00', $recurring->lastFailureAt()?->format('Y-m-d H:i'));
        $recurring->clearFailure();
        self::assertNull($recurring->lastFailureCode());
        self::assertNull($recurring->lastFailureAt());
        $this->expectException(\DomainException::class);
        $recurring->recordFailure(new \DateTimeImmutable(), '');
    }

    public function testEveryChangeOfStateRaisesTheVersionByOne(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $version = $recurring->version();

        $recurring->pause(new \DateTimeImmutable('2026-11-29 09:00'));
        self::assertSame(++$version, $recurring->version());
        $recurring->pause(new \DateTimeImmutable('2026-11-29 10:00'));
        self::assertSame($version, $recurring->version());

        self::assertSame([], $recurring->resume(new \DateTimeImmutable('2026-11-29')));
        self::assertSame(++$version, $recurring->version());
        self::assertSame([], $recurring->resume(new \DateTimeImmutable('2026-11-29')));
        self::assertSame($version, $recurring->version());

        $recurring->skip(new \DateTimeImmutable('2026-12-07'));
        self::assertSame(++$version, $recurring->version());
        $recurring->skip(new \DateTimeImmutable('2026-12-07'));
        self::assertSame($version, $recurring->version());

        $recurring->change($this->template($this->weekly('2026-11-30'), title: 'Other'), new \DateTimeImmutable('2026-11-29'));
        self::assertSame(++$version, $recurring->version());

        $recurring->end(new \DateTimeImmutable('2026-11-29 11:00'));
        self::assertSame(++$version, $recurring->version());
        $recurring->end(new \DateTimeImmutable('2026-11-29 12:00'));
        self::assertSame($version, $recurring->version());
    }

    public function testAnEndedOneRefusesToSkipBecauseItIsEnded(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->end(new \DateTimeImmutable('2026-11-29 10:00'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('An ended recurring task stays ended.');
        $recurring->skip(new \DateTimeImmutable('2026-12-07'));
    }

    public function testSkippedDatesStayInOrder(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));

        $recurring->skip(new \DateTimeImmutable('2026-12-14'));
        $recurring->skip(new \DateTimeImmutable('2026-12-07'));

        self::assertSame(['2026-12-07', '2026-12-14'], $recurring->skippedOn());
    }

    public function testResumingOnADateOfTheScheduleDoesNotSkipThatDate(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $recurring->pause(new \DateTimeImmutable('2026-11-29 09:00'));

        $skipped = $recurring->resume(new \DateTimeImmutable('2026-12-07'));

        self::assertSame(['2026-11-30'], $this->days($skipped));
        self::assertSame('2026-12-07', $recurring->nextOccurrence()?->format('Y-m-d'));
    }

    public function testAFailureCodeOf64CharactersIsKeptAndOf65Refused(): void
    {
        $recurring = $this->recurring($this->weekly('2026-11-30'));
        $code = str_repeat('a', RecurringTask::MAX_FAILURE_CODE_LENGTH);

        $recurring->recordFailure(new \DateTimeImmutable('2026-11-30 01:00'), $code);
        self::assertSame($code, $recurring->lastFailureCode());

        $this->expectException(\DomainException::class);
        $recurring->recordFailure(new \DateTimeImmutable('2026-11-30 02:00'), $code.'a');
    }

    public function testTheSameLabelOrWatcherIsKeptOnce(): void
    {
        $template = new RecurringTaskTemplate('Slot', '', null, null, Priority::None, false, null, ['a', 'a', 'b'], ['x', 'y', 'x', 'z'], [], [], $this->weekly('2026-11-30'));

        $recurring = new RecurringTask($this->project, $template, Id::generate(), new \DateTimeImmutable('2026-11-27 10:00'));

        self::assertSame(['a', 'b'], $recurring->template()->labelIds);
        self::assertSame(['x', 'y', 'z'], $recurring->template()->watcherIds);
    }

    public function testAChangeToTheSameDaysKeepsTheStoredDates(): void
    {
        $recurrence = new Recurrence(new \DateTimeImmutable('2026-11-30'), RecurrenceUnit::Week, 1, false, RecurrenceEnd::OnDate, new \DateTimeImmutable('2027-01-04'), null);
        $recurring = $this->recurring($recurrence);
        $next = $recurring->nextOccurrence();
        $first = $this->stored($recurring, 'firstOn');
        $endOn = $this->stored($recurring, 'endOn');

        $recurring->change($this->template($recurrence, title: 'Other'), new \DateTimeImmutable('2026-11-29'));

        self::assertSame($next, $recurring->nextOccurrence());
        self::assertSame($first, $this->stored($recurring, 'firstOn'));
        self::assertSame($endOn, $this->stored($recurring, 'endOn'));
    }

    public function testATemplateCreatesOnTheDateAndHasNoDueOffsetUnlessGiven(): void
    {
        $template = new RecurringTaskTemplate('Slot', '', null, null, Priority::None, false, null, [], [], [], [], $this->weekly('2026-11-30'));

        self::assertSame(0, $template->createDaysBefore);
        self::assertNull($template->dueDaysAfter);
    }

    public function testATaskOfAnotherProjectIsNotTheOccurrenceOfARecurringTask(): void
    {
        $other = new Project('Other', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-01 08:00'));
        $recurring = new RecurringTask($other, $this->template($this->weekly('2026-11-30')), Id::generate(), new \DateTimeImmutable('2026-11-27 10:00'));
        $status = new ProjectStatus($this->project, 'To do', StatusType::Open, 0);
        $task = new Task($this->project, 'Slot', $status, 0, Id::generate(), new \DateTimeImmutable());

        try {
            $task->occurrenceOf($recurring, new \DateTimeImmutable('2026-11-30'));
            self::fail('A recurring task of another project was linked.');
        } catch (\DomainException $e) {
            self::assertSame('The recurring task belongs to another project.', $e->getMessage());
        }
        self::assertNull($task->recurringTask());
    }

    private function stored(RecurringTask $recurring, string $property): mixed
    {
        return new \ReflectionProperty(RecurringTask::class, $property)->getValue($recurring);
    }

    private function weekly(string $first): Recurrence
    {
        return new Recurrence(new \DateTimeImmutable($first), RecurrenceUnit::Week, 1, false, RecurrenceEnd::Never, null, null);
    }

    /**
     * @param list<string> $subtasks
     */
    private function recurring(Recurrence $recurrence, int $createDaysBefore = 0, ?int $dueDaysAfter = null, ?int $estimate = null, array $subtasks = []): RecurringTask
    {
        return new RecurringTask($this->project, $this->template($recurrence, createDaysBefore: $createDaysBefore, dueDaysAfter: $dueDaysAfter, estimate: $estimate, subtasks: $subtasks), Id::generate(), new \DateTimeImmutable('2026-11-27 10:00'));
    }

    /**
     * @param list<string> $subtasks
     */
    private function template(Recurrence $recurrence, string $title = 'Slot for small changes {week}', ?Section $section = null, int $createDaysBefore = 0, ?int $dueDaysAfter = null, ?int $estimate = null, array $subtasks = []): RecurringTaskTemplate
    {
        return new RecurringTaskTemplate($title, '', $section, null, Priority::None, false, $estimate, [], [], [], $subtasks, $recurrence, $createDaysBefore, $dueDaysAfter);
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private function days(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}
