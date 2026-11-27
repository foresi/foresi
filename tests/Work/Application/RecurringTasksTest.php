<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Application\Api\CreateRecurringTaskInput;
use Foresi\Work\Application\Api\UpdateRecurringTaskInput;
use Foresi\Work\Application\CreateRecurringTasks;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskWatcher;
use Foresi\Work\Domain\WorkShare;

/**
 * Recurring tasks (plan 37.4): the daily run creates an ordinary task of the project on
 * each date, once, whether or not the last one is done, catching up at most four a run;
 * the task gets the fields of the recurring task and fresh subtasks; a date that cannot be
 * created is recorded and tried again.
 */
final class RecurringTasksTest extends WorkTestCase
{
    private ManageRecurringTasks $recurring;
    private CreateRecurringTasks $run;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recurring = self::getContainer()->get(ManageRecurringTasks::class);
        $this->run = self::getContainer()->get(CreateRecurringTasks::class);
        $this->project = $this->project();
    }

    public function testTheRunCreatesTheSlotOfItsWeekWithEverythingItsTaskNeeds(): void
    {
        $section = $this->sections->add($this->project->id(), 'Retainer');
        $label = $this->labels->create('Small changes', LabelColour::Green);
        // 30 November 2026 is a Monday.
        $recurring = $this->create(
            title: 'Slot {week}/{year}',
            sectionId: $section->id(),
            responsibleId: $this->member->id(),
            priority: Priority::High->value,
            estimateMinutes: 120,
            labelIds: [$label->id()],
            subtasks: ['Collect requests {month}/{year}', 'Report'],
            description: 'Small changes of week {week}.',
        );
        self::assertSame([], $this->tasksOf($recurring), 'nothing before its Monday');

        $this->at('2026-11-30 02:00');
        $this->run->run(null);
        $this->run->run(null);

        $tasks = $this->tasksOf($recurring);
        self::assertCount(1, $tasks, 'created once however often it runs');
        $task = $tasks[0];
        self::assertSame('Slot 49/2026', $task->title());
        self::assertSame('Small changes of week 49.', $task->description());
        self::assertSame($section->id(), $task->section()?->id());
        self::assertSame($this->member->id(), $task->responsible()?->id());
        self::assertSame(Priority::High, $task->priority());
        self::assertSame(120, $task->estimateMinutes());
        self::assertSame('2026-12-06', $task->dueDate()?->format('Y-m-d'));
        self::assertSame('2026-11-30', $task->occurrenceOn()?->format('Y-m-d'));
        self::assertSame($this->owner->user()->id(), $task->createdBy());
        self::assertSame(['Small changes'], array_map(static fn (TaskLabel $link): string => $link->label()->name(), $this->em->getRepository(TaskLabel::class)->findBy(['task' => $task])));
        $watchers = array_map(static fn (TaskWatcher $watcher): string => $watcher->membership()->id(), $this->em->getRepository(TaskWatcher::class)->findBy(['task' => $task]));
        sort($watchers);
        $expected = [$this->owner->id(), $this->member->id()];
        sort($expected);
        self::assertSame($expected, $watchers);
        $subtasks = $this->em->getRepository(Task::class)->findBy(['parent' => $task], ['position' => 'ASC']);
        self::assertSame(['Collect requests 11/2026', 'Report'], array_map(static fn (Task $subtask): string => $subtask->title(), $subtasks));
        self::assertFalse($subtasks[0]->isCompleted());
        self::assertNull($subtasks[0]->recurringTask());

        $stored = $this->stored($recurring);
        self::assertSame(1, $stored->createdCount());
        self::assertSame('2026-12-07', $stored->nextOccurrence()?->format('Y-m-d'));
    }

    public function testANewSlotComesWhileTheLastIsOpenAndTheCatchUpTakesFourARun(): void
    {
        $recurring = $this->create();

        $this->at('2026-11-30 09:00');
        $this->run->run(null);
        $this->at('2027-01-20 09:00');
        $this->run->run(null);
        $afterOneRun = $this->tasksOf($recurring);
        self::assertCount(5, $afterOneRun, 'the first, then four of the seven missed');

        $this->run->run(null);
        self::assertSame(
            ['2027-01-18', '2027-01-11', '2027-01-04', '2026-12-28', '2026-12-21', '2026-12-14', '2026-12-07', '2026-11-30'],
            array_map(static fn (Task $task): string => $task->occurrenceOn()?->format('Y-m-d') ?? '', $this->tasksOf($recurring)),
        );
        self::assertSame('2027-01-25', $this->stored($recurring)->nextOccurrence()?->format('Y-m-d'));
    }

    public function testSettingItUpOnItsDayCreatesTheTaskAtOnce(): void
    {
        $this->at('2026-11-30 10:00');

        $recurring = $this->create(createDaysBefore: 0, dueDaysAfter: 2);

        $tasks = $this->tasksOf($recurring);
        self::assertCount(1, $tasks);
        self::assertSame('2026-12-02', $tasks[0]->dueDate()?->format('Y-m-d'));
    }

    public function testATaskCanBeCreatedDaysBeforeItsDate(): void
    {
        $recurring = $this->create(createDaysBefore: 3);

        $this->at('2026-11-26 09:00');
        $this->run->run(null);
        self::assertSame([], $this->tasksOf($recurring));
        $this->at('2026-11-27 09:00');
        $this->run->run(null);
        self::assertSame(['2026-11-30'], array_map(static fn (Task $task): string => $task->occurrenceOn()?->format('Y-m-d') ?? '', $this->tasksOf($recurring)));
    }

    public function testAnArchivedProjectIsRecordedAndTheDateWaits(): void
    {
        $recurring = $this->create();
        $this->projects->archive($this->project->id());

        $this->at('2026-11-30 09:00');
        $this->run->run(null);
        self::assertSame([], $this->tasksOf($recurring));
        self::assertSame('archived', $this->stored($recurring)->lastFailureCode());
        self::assertSame('2026-11-30', $this->stored($recurring)->nextOccurrence()?->format('Y-m-d'));

        $this->projects->restore($this->project->id());
        $this->run->run(null);
        self::assertCount(1, $this->tasksOf($recurring));
        self::assertNull($this->stored($recurring)->lastFailureCode());
    }

    public function testAPrivateTaskIsSharedWithItsPeople(): void
    {
        $recurring = $this->create(responsibleId: $this->member->id(), private: true);

        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        $task = $this->tasksOf($recurring)[0];
        self::assertTrue($task->isPrivate());
        $shared = array_map(static fn (WorkShare $share): string => $share->membership()->id(), $this->em->getRepository(WorkShare::class)->findBy(['task' => $task]));
        self::assertContains($this->member->id(), $shared);
        self::assertSame($this->member->id(), $task->responsible()?->id());
    }

    public function testPauseSkipsWhatFallsInItAndSkipLeavesOutOneDate(): void
    {
        $recurring = $this->create();
        $this->recurring->skip($recurring->id(), '2026-12-07');
        $this->recurring->pause($recurring->id());

        $this->at('2026-12-02 09:00');
        $this->run->run(null);
        self::assertSame([], $this->tasksOf($recurring));
        self::assertSame(['2026-11-30'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $this->recurring->resume($recurring->id())));

        self::assertSame(['2026-12-14', '2026-12-21'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $this->stored($recurring)->upcoming(2)));
        try {
            $this->recurring->skip($recurring->id(), '2026-12-15');
            self::fail('A day it does not fall on was skipped.');
        } catch (WorkRefused $refused) {
            self::assertSame('not_an_occurrence', $refused->reason);
        }
    }

    public function testAnEditGoesOnForTheTasksToComeAndAnEndedOneStaysEnded(): void
    {
        $recurring = $this->create();
        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        $this->recurring->update(new UpdateRecurringTaskInput($recurring->id(), title: 'Weekly slot', repeat: 'day'));
        $stored = $this->stored($recurring);
        self::assertSame('Weekly slot', $stored->title());
        self::assertSame('2026-12-01', $stored->nextOccurrence()?->format('Y-m-d'));
        self::assertSame('Slot', $this->tasksOf($recurring)[0]->title(), 'the task created stays');

        $this->recurring->end($recurring->id());
        self::assertSame(RecurringTaskState::Ended, $this->stored($recurring)->state());
        $this->expectExceptionObject(new WorkRefused('recurring_ended'));
        $this->recurring->update(new UpdateRecurringTaskInput($recurring->id(), title: 'Again'));
    }

    public function testOnlyThePeopleOfTheOrganisationSetThemUp(): void
    {
        $this->signIn($this->guest);

        $this->expectExceptionObject(new WorkRefused('not_allowed'));
        $this->create();
    }

    public function testWhatDoesNotHoldTogetherIsRefused(): void
    {
        foreach ([
            'schedule' => fn (): RecurringTask => $this->create(every: 0),
            'date' => fn (): RecurringTask => $this->create(firstOn: '2026-11-31'),
            'create_days_before' => fn (): RecurringTask => $this->create(createDaysBefore: 91),
            'due_days_after' => fn (): RecurringTask => $this->create(dueDaysAfter: -1),
            'responsible' => fn (): RecurringTask => $this->create(responsibleId: $this->guest->id()),
            'unknown' => fn (): RecurringTask => $this->create(labelIds: ['00000000-0000-4000-8000-000000000000']),
            'name' => fn (): RecurringTask => $this->create(title: ' '),
        ] as $reason => $create) {
            try {
                $create();
                self::fail($reason);
            } catch (WorkRefused $refused) {
                self::assertSame($reason, $refused->reason, $reason);
            }
        }
        self::assertSame([], $this->recurring->all());
    }

    public function testTheLimitsAreTheLastValuesAccepted(): void
    {
        $title = str_repeat('é', Task::MAX_TITLE_LENGTH);
        $subtasks = array_fill(0, RecurringTask::MAX_SUBTASKS - 1, 'Step');
        $recurring = $this->create(
            title: '  '.$title.'  ',
            estimateMinutes: Task::MAX_ESTIMATE_MINUTES,
            subtasks: [' '.$title.' ', '', '   ', ...$subtasks],
            createDaysBefore: RecurringTask::MAX_CREATE_DAYS_BEFORE,
            dueDaysAfter: RecurringTask::MAX_DUE_DAYS_AFTER,
        );

        self::assertSame($title, $recurring->title());
        self::assertSame(Task::MAX_ESTIMATE_MINUTES, $recurring->estimateMinutes());
        self::assertSame([$title, ...$subtasks], $recurring->subtasks(), 'trimmed, the blank ones left out');
        self::assertSame(RecurringTask::MAX_CREATE_DAYS_BEFORE, $recurring->createDaysBefore());
        self::assertSame(RecurringTask::MAX_DUE_DAYS_AFTER, $recurring->dueDaysAfter());

        $lowest = $this->create(estimateMinutes: 1, dueDaysAfter: 0);
        self::assertSame(1, $lowest->estimateMinutes());
        self::assertSame(0, $lowest->dueDaysAfter());
    }

    public function testJustPastTheLimitsIsRefused(): void
    {
        $tooLong = str_repeat('é', Task::MAX_TITLE_LENGTH + 1);
        foreach ([
            'name' => fn (): RecurringTask => $this->create(title: $tooLong),
            'estimate' => fn (): RecurringTask => $this->create(estimateMinutes: 0),
            'estimate ' => fn (): RecurringTask => $this->create(estimateMinutes: Task::MAX_ESTIMATE_MINUTES + 1),
            'subtasks' => fn (): RecurringTask => $this->create(subtasks: array_fill(0, RecurringTask::MAX_SUBTASKS + 1, 'Step')),
            'subtasks ' => fn (): RecurringTask => $this->create(subtasks: [$tooLong]),
            'due_days_after' => fn (): RecurringTask => $this->create(dueDaysAfter: RecurringTask::MAX_DUE_DAYS_AFTER + 1),
            'create_days_before' => fn (): RecurringTask => $this->create(createDaysBefore: -1),
            'unknown' => fn (): RecurringTask => $this->create(priority: 'critical'),
            'schedule' => fn (): RecurringTask => $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', repeat: 'hour')),
            'schedule ' => fn (): RecurringTask => $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', end: 'someday')),
            'watcher' => fn (): RecurringTask => $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', watcherIds: [$this->guest->id()])),
        ] as $reason => $create) {
            try {
                $create();
                self::fail($reason);
            } catch (WorkRefused $refused) {
                self::assertSame(trim($reason), $refused->reason, $reason);
            }
        }
        self::assertSame([], $this->recurring->all());
    }

    public function testOnlyTheChosenEndKeepsItsDateOrCount(): void
    {
        $never = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', endsOn: '2027-03-01', endsAfter: 3));
        self::assertNull($never->recurrence()->endOn());
        self::assertNull($never->recurrence()->endAfter());

        $onDate = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', end: 'on_date', endsOn: '2027-03-01', endsAfter: 3));
        self::assertSame('2027-03-01', $onDate->recurrence()->endOn()?->format('Y-m-d'));
        self::assertNull($onDate->recurrence()->endAfter());

        $afterCount = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', end: 'after_count', endsOn: '2027-03-01', endsAfter: 3));
        self::assertNull($afterCount->recurrence()->endOn());
        self::assertSame(3, $afterCount->recurrence()->endAfter());

        // An update that leaves the end alone keeps it.
        $updated = $this->recurring->update(new UpdateRecurringTaskInput($onDate->id(), title: 'Renamed'));
        self::assertSame('2027-03-01', $updated->recurrence()->endOn()?->format('Y-m-d'));
        $updated = $this->recurring->update(new UpdateRecurringTaskInput($afterCount->id(), title: 'Renamed'));
        self::assertSame(3, $updated->recurrence()->endAfter());
    }

    public function testAGuestReadsNoRecurringTaskAndTheOrganisationDoes(): void
    {
        $recurring = $this->create();

        $this->signIn($this->guest);
        foreach ([
            fn (): mixed => $this->recurring->get($recurring->id()),
            fn (): mixed => $this->recurring->all(),
        ] as $read) {
            try {
                $read();
                self::fail('A guest read a recurring task.');
            } catch (WorkRefused $refused) {
                self::assertSame('not_allowed', $refused->reason);
            }
        }

        $this->signIn($this->member);
        self::assertSame($recurring->id(), $this->recurring->get($recurring->id())->id());
    }

    public function testAnUnknownRecurringTaskIsRefusedAsUnknown(): void
    {
        $this->expectExceptionObject(new WorkRefused('unknown'));
        $this->recurring->get('00000000-0000-4000-8000-000000000000');
    }

    public function testTheListIsAListOfTheChosenStateWithKeysFromZero(): void
    {
        $first = $this->create(title: 'First');
        $second = $this->create(title: 'Second');
        $this->recurring->end($first->id());

        $active = $this->recurring->all(RecurringTaskState::Active);
        self::assertSame([0], array_keys($active));
        self::assertSame($second->id(), $active[0]->id());
        $ended = $this->recurring->all(RecurringTaskState::Ended);
        self::assertSame([0], array_keys($ended));
        self::assertSame($first->id(), $ended[0]->id());
        self::assertSame([0, 1], array_keys($this->recurring->all()));
    }

    public function testTheTasksItCreatedAreTheLatestTenHiddenWhenDeletedOrNotSeen(): void
    {
        $daily = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Daily', '2026-11-30', repeat: 'day'));
        $this->at('2026-12-20 09:00');
        for ($run = 0; $run < 4; ++$run) {
            $this->run->run(null);
        }
        self::assertCount(16, $this->tasksOf($daily));
        self::assertCount(10, $this->recurring->tasks($daily), 'ten by default');
        self::assertCount(3, $this->recurring->tasks($daily, 3));

        $this->tasks->delete($this->tasksOf($daily)[0]->id());
        $tasks = $this->recurring->tasks($daily, 1);
        self::assertSame([], $tasks, 'the deleted one is left out');

        $private = $this->create(title: 'Mine', firstOn: '2026-12-20', private: true);
        $this->run->run(null);
        self::assertCount(1, $this->recurring->tasks($private), 'its owner sees it');
        $this->signIn($this->member);
        self::assertSame([], $this->recurring->tasks($private), 'a private task is not seen by those it is not shared with');
    }

    public function testASkippedDateIsNotCreatedAndTheNextOneIsCreatedAtOnce(): void
    {
        $recurring = $this->create();
        $this->at('2026-12-08 09:00');

        $skipped = $this->recurring->skip($recurring->id(), '2026-11-30');

        self::assertSame(['2026-11-30'], $skipped->skippedOn());
        self::assertSame(['2026-12-07'], array_map(static fn (Task $task): string => $task->occurrenceOn()?->format('Y-m-d') ?? '', $this->tasksOf($recurring)));
    }

    public function testADateBeforeTheNextOneIsAlreadyCreated(): void
    {
        $recurring = $this->create();
        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        $this->expectExceptionObject(new WorkRefused('occurrence_created'));
        $this->recurring->skip($recurring->id(), '2026-11-30');
    }

    public function testAnEndedRecurringTaskCannotBeSkippedOrResumed(): void
    {
        $recurring = $this->create();
        $this->recurring->end($recurring->id());

        foreach ([
            fn (): mixed => $this->recurring->skip($recurring->id(), '2026-11-30'),
            fn (): mixed => $this->recurring->resume($recurring->id()),
            fn (): mixed => $this->recurring->pause($recurring->id()),
        ] as $change) {
            try {
                $change();
                self::fail('An ended recurring task was changed.');
            } catch (WorkRefused $refused) {
                self::assertSame('recurring_ended', $refused->reason);
            }
        }
    }

    public function testResumingReturnsEveryDateOfThePauseAndCreatesTheTaskDueAtOnce(): void
    {
        $recurring = $this->create();
        $this->recurring->pause($recurring->id());
        $this->at('2026-12-14 09:00');

        $skipped = $this->recurring->resume($recurring->id());

        self::assertSame(['2026-11-30', '2026-12-07'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $skipped));
        self::assertSame(['2026-12-14'], array_map(static fn (Task $task): string => $task->occurrenceOn()?->format('Y-m-d') ?? '', $this->tasksOf($recurring)));
    }

    public function testAnEditThatLeavesATaskDueCreatesItAtOnce(): void
    {
        $recurring = $this->create();
        $this->at('2026-11-30 10:00');

        $this->recurring->update(new UpdateRecurringTaskInput($recurring->id(), title: 'Renamed'));

        $tasks = $this->tasksOf($recurring);
        self::assertCount(1, $tasks);
        self::assertSame('Renamed', $tasks[0]->title());
    }

    public function testAPersonWhoIsUnknownIsRefused(): void
    {
        foreach ([
            'responsible' => fn (): RecurringTask => $this->create(responsibleId: '00000000-0000-4000-8000-000000000000'),
            'watcher' => fn (): RecurringTask => $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', watcherIds: ['00000000-0000-4000-8000-000000000000'])),
        ] as $reason => $create) {
            try {
                $create();
                self::fail($reason);
            } catch (WorkRefused $refused) {
                self::assertSame($reason, $refused->reason, $reason);
            }
        }
    }

    public function testAnUpdateTurnsWhatItIsGivenIntoTitlesOfSubtasks(): void
    {
        // What an API client may send in a list that the input types as strings.
        $given = [123, null, 'Report', 4.5];
        $recurring = $this->create(subtasks: ['Old']);

        $updated = $this->recurring->update(new \ReflectionClass(UpdateRecurringTaskInput::class)->newInstanceArgs(['recurringTaskId' => $recurring->id(), 'subtasks' => $given]));

        self::assertSame(['123', 'Report', '4.5'], $updated->subtasks());
    }

    public function testTheCustomFieldsAreKeptAndReplacedAsAWhole(): void
    {
        LentFields::lend(self::getContainer(), TaskExtension::RECORD);

        $recurring = $this->recurring->create(
            new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30'),
            ['test_fields_code' => 'A-1', 'test_fields_seats' => 4, 'test_fields_size' => 'large', 'test_fields_flag' => null],
        );
        self::assertSame(['test_fields_code' => 'A-1', 'test_fields_seats' => 4, 'test_fields_size' => 'large'], $recurring->customFields());

        $kept = $this->recurring->update(new UpdateRecurringTaskInput($recurring->id(), title: 'Renamed'));
        self::assertSame(['test_fields_code' => 'A-1', 'test_fields_seats' => 4, 'test_fields_size' => 'large'], $kept->customFields());

        $replaced = $this->recurring->update(new UpdateRecurringTaskInput($recurring->id(), title: 'Renamed'), ['test_fields_code' => 'B-2']);
        self::assertSame(['test_fields_code' => 'B-2'], $replaced->customFields());

        $this->expectExceptionObject(new WorkRefused('custom_fields'));
        $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30'), ['no_such_field' => 'x']);
    }

    public function testATaskIsNotPrivateUnlessItIsMade(): void
    {
        $recurring = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30'));

        self::assertFalse($recurring->isPrivate());
    }

    /**
     * @param list<string> $labelIds
     * @param list<string> $subtasks
     */
    private function create(
        string $title = 'Slot',
        string $firstOn = '2026-11-30',
        int $every = 1,
        ?string $sectionId = null,
        ?string $responsibleId = null,
        string $priority = 'none',
        bool $private = false,
        ?int $estimateMinutes = null,
        array $labelIds = [],
        array $subtasks = [],
        string $description = '',
        int $createDaysBefore = 0,
        ?int $dueDaysAfter = null,
    ): RecurringTask {
        return $this->recurring->create(new CreateRecurringTaskInput(
            $this->project->id(),
            $title,
            $firstOn,
            every: $every,
            createDaysBefore: $createDaysBefore,
            dueDaysAfter: $dueDaysAfter,
            description: $description,
            sectionId: $sectionId,
            responsibleId: $responsibleId,
            priority: $priority,
            private: $private,
            estimateMinutes: $estimateMinutes,
            labelIds: $labelIds,
            subtasks: $subtasks,
        ));
    }

    private function at(string $moment): void
    {
        $this->clock->modify($moment.' Europe/Prague');
    }

    private function stored(RecurringTask $recurring): RecurringTask
    {
        $this->em->clear();

        return $this->em->find(RecurringTask::class, $recurring->id()) ?? throw new \LogicException('Gone.');
    }

    /**
     * @return list<Task>
     */
    private function tasksOf(RecurringTask $recurring): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)->findBy(['recurringTask' => $recurring->id()], ['occurrenceOn' => 'DESC']);

        return $tasks;
    }
}
