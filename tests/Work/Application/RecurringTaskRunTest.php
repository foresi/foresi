<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Doctrine\DBAL\Connection;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Application\Api\CreateRecurringTaskInput;
use Foresi\Work\Application\CreateRecurringOccurrence;
use Foresi\Work\Application\CreateRecurringTasks;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Application\OccurrenceRefused;
use Foresi\Work\Application\RecurringTasks;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskWatcher;
use Psr\Log\AbstractLogger;

/**
 * What the run of recurring tasks does with one: the task it creates and its subtasks, the
 * people and labels that still hold, the custom fields, the events, the catch-up of a run,
 * and a refused date recorded once and tried again.
 */
final class RecurringTaskRunTest extends WorkTestCase
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

    public function testTheTaskAndItsSubtasksStartInTheFirstOpenStatusAndFollowEachOtherInOrder(): void
    {
        $statuses = $this->statuses($this->project);
        $done = $statuses[2];
        $this->workflow->move($done->id(), 0);
        $recurring = $this->create(subtasks: ['One', 'Two']);

        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        $task = $this->tasksOf($recurring)[0];
        self::assertSame(StatusType::Open, $task->status()->type());
        self::assertSame($statuses[0]->id(), $task->status()->id());
        $subtasks = $this->em->getRepository(Task::class)->findBy(['parent' => $task], ['position' => 'ASC']);
        self::assertSame(['One', 'Two'], array_map(static fn (Task $subtask): string => $subtask->title(), $subtasks));
        self::assertSame([$task->position() + 1, $task->position() + 2], array_map(static fn (Task $subtask): int => $subtask->position(), $subtasks));
        self::assertSame([$statuses[0]->id(), $statuses[0]->id()], array_map(static fn (Task $subtask): string => $subtask->status()->id(), $subtasks));
    }

    public function testTheTaskAndEachSubtaskRecordTheirCreationAndTheLastOneEndsTheRecurringTask(): void
    {
        $recurring = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Slot', '2026-11-30', end: 'after_count', endsAfter: 2, subtasks: ['Step']));

        $this->at('2026-11-30 09:00');
        $this->run->run(null);
        $task = $this->tasksOf($recurring)[0];
        $subtask = $this->em->getRepository(Task::class)->findOneBy(['parent' => $task]);
        self::assertInstanceOf(Task::class, $subtask);
        self::assertSame([
            ['work.task.created', ['task_id' => $task->id(), 'project_id' => $this->project->id(), 'parent_id' => null]],
            ['work.task.created', ['task_id' => $subtask->id(), 'project_id' => $this->project->id(), 'parent_id' => $task->id()]],
        ], $this->events("name = 'work.task.created'"), 'the first of two ends nothing');
        self::assertSame([], $this->events("name = 'work.recurring_task.ended'"));

        $this->at('2026-12-07 09:00');
        $this->run->run(null);
        self::assertSame(
            [['work.recurring_task.ended', ['recurring_task_id' => $recurring->id(), 'project_id' => $this->project->id()]]],
            $this->events("name = 'work.recurring_task.ended'"),
        );
    }

    public function testTheCustomFieldsOfTheRecurringTaskAreWrittenOnItsTask(): void
    {
        $fields = LentFields::lend(self::getContainer(), TaskExtension::RECORD);
        $with = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'With', '2026-11-30'), ['test_fields_code' => 'RT-1']);
        $without = $this->recurring->create(new CreateRecurringTaskInput($this->project->id(), 'Without', '2026-11-30'));

        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        self::assertSame('RT-1', $fields->value(TaskExtension::RECORD, $this->tasksOf($with)[0]->id(), 'test_fields_code'));
        self::assertNull($fields->value(TaskExtension::RECORD, $this->tasksOf($without)[0]->id(), 'test_fields_code'));
    }

    public function testALabelInTheTrashIsLeftOutAndTheRestStays(): void
    {
        $kept = $this->labels->create('Kept', LabelColour::Green);
        $gone = $this->labels->create('Gone', LabelColour::Red);
        $recurring = $this->create(labelIds: [$gone->id(), $kept->id()]);
        $this->labels->delete($gone->id());

        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        $task = $this->tasksOf($recurring)[0];
        self::assertSame(['Kept'], array_map(static fn (TaskLabel $link): string => $link->label()->name(), $this->em->getRepository(TaskLabel::class)->findBy(['task' => $task])));
    }

    public function testTheOwnerAndTheWatchersFollowAndWhoCannotSeeTheTaskDoesNot(): void
    {
        $recurring = $this->create(watcherIds: [$this->member->id()]);

        $this->at('2026-11-30 09:00');
        $this->run->run(null);
        self::assertSame($this->sorted([$this->owner->id(), $this->member->id()]), $this->watchersOf($this->tasksOf($recurring)[0]));

        $member = $this->em->find(Membership::class, $this->member->id()) ?? throw new \LogicException('Gone.');
        $member->changeRole(Role::Guest, $this->em->find(Membership::class, $this->guest->id())?->party());
        $this->em->flush();
        $this->at('2026-12-07 09:00');
        $this->run->run(null);
        $latest = $this->tasksOf($recurring)[0];
        self::assertSame('2026-12-07', $latest->occurrenceOn()?->format('Y-m-d'));
        self::assertSame([$this->owner->id()], $this->watchersOf($latest), 'a guest the task is not shared with does not follow it');
    }

    public function testTheTaskIsDueAtTheEndOfItsPeriodOrDaysAfterItsDate(): void
    {
        $period = $this->create(title: 'Period');
        $after = $this->create(title: 'After', dueDaysAfter: 3);

        $this->at('2026-11-30 09:00');
        $this->run->run(null);

        self::assertSame('2026-12-06', $this->tasksOf($period)[0]->dueDate()?->format('Y-m-d'));
        self::assertSame('2026-12-03', $this->tasksOf($after)[0]->dueDate()?->format('Y-m-d'));
    }

    public function testRunningOneRecurringTaskCreatesWhatIsDueByTheDayGivenAndReturnsItsIds(): void
    {
        $recurring = $this->create();
        $other = $this->create(title: 'Other');
        self::assertSame([], $this->run->runOne($recurring->id()), 'nothing is due on the clock\'s day');

        $ids = $this->run->runOne($recurring->id(), new \DateTimeImmutable('2026-11-30'));

        self::assertCount(1, $ids);
        self::assertSame($ids, array_map(static fn (Task $task): string => $task->id(), $this->tasksOf($recurring)));
        self::assertSame([], $this->tasksOf($other), 'the others wait for their run');
    }

    public function testARunOneCatchesUpAtMostFourDatesAndReturnsThemAll(): void
    {
        $recurring = $this->create();
        $this->at('2027-01-20 09:00');

        $ids = $this->run->runOne($recurring->id());

        self::assertCount(CreateRecurringTasks::MAX_PER_RUN, $ids);
        self::assertSame(
            ['2026-12-21', '2026-12-14', '2026-12-07', '2026-11-30'],
            array_map(static fn (Task $task): string => $task->occurrenceOn()?->format('Y-m-d') ?? '', $this->tasksOf($recurring)),
        );
    }

    public function testTodayIsTheDayBeforeUntilOneInTheMorning(): void
    {
        $this->at('2026-11-29 23:59');
        self::assertSame('2026-11-29', $this->run->today()->format('Y-m-d'));
        $this->at('2026-11-30 00:00');
        self::assertSame('2026-11-30', $this->run->today()->format('Y-m-d'));
    }

    public function testRunningOneThatIsGoneCreatesNothingAndFailsNot(): void
    {
        self::assertSame([], $this->run->runOne('00000000-0000-4000-8000-000000000000', new \DateTimeImmutable('2026-11-30')));
    }

    public function testARefusedDateIsRecordedOnceWithItsTimeAndClearedWhenItsTaskIsCreated(): void
    {
        $recurring = $this->create();
        $this->projects->archive($this->project->id());

        $this->at('2026-11-30 09:00');
        self::assertSame([], $this->run->runOne($recurring->id()));
        $first = $this->stored($recurring);
        self::assertSame('archived', $first->lastFailureCode());
        self::assertSame('2026-11-30 09:00', $first->lastFailureAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        $this->at('2026-11-30 10:00');
        $this->run->runOne($recurring->id());
        self::assertSame('2026-11-30 09:00', $this->stored($recurring)->lastFailureAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), 'the same failure is not recorded again');

        $this->projects->restore($this->project->id());
        self::assertCount(1, $this->run->runOne($recurring->id()));
        $cleared = $this->stored($recurring);
        self::assertNull($cleared->lastFailureCode());
        self::assertNull($cleared->lastFailureAt());
    }

    public function testARefusedDateIsLoggedOnceWithItsRecurringTaskAndCode(): void
    {
        $recurring = $this->create();
        $this->projects->archive($this->project->id());
        $this->at('2026-11-30 09:00');
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $container = self::getContainer();
        $run = new CreateRecurringTasks(
            $container->get(RecurringTasks::class),
            $container->get(CreateRecurringOccurrence::class),
            $container->get(TransactionalWork::class),
            $container->get(InstanceClock::class),
            $this->clock,
            $logger,
        );

        $run->runOne($recurring->id());
        $run->runOne($recurring->id());
        $this->projects->restore($this->project->id());
        $run->runOne($recurring->id());

        self::assertSame([['warning', 'The recurring task {recurring} created no task: {code}.', ['recurring' => $recurring->id(), 'code' => 'archived']]], $logger->records);
    }

    public function testTheRefusalNamesItsReason(): void
    {
        $refused = new OccurrenceRefused(OccurrenceRefused::NO_STATUS);

        self::assertSame('no_status', $refused->reason);
        self::assertSame('The task of the recurring task was not created: no_status.', $refused->getMessage());
    }

    /**
     * @param list<string> $labelIds
     * @param list<string> $watcherIds
     * @param list<string> $subtasks
     */
    private function create(string $title = 'Slot', array $labelIds = [], array $watcherIds = [], array $subtasks = [], ?int $dueDaysAfter = null): RecurringTask
    {
        return $this->recurring->create(new CreateRecurringTaskInput(
            $this->project->id(),
            $title,
            '2026-11-30',
            dueDaysAfter: $dueDaysAfter,
            labelIds: $labelIds,
            watcherIds: $watcherIds,
            subtasks: $subtasks,
        ));
    }

    private function at(string $moment): void
    {
        $this->clock->modify($moment);
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

    /**
     * @return list<string> the ids of the memberships following the task, sorted
     */
    private function watchersOf(Task $task): array
    {
        return $this->sorted(array_map(static fn (TaskWatcher $watcher): string => $watcher->membership()->id(), $this->em->getRepository(TaskWatcher::class)->findBy(['task' => $task])));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{string, array<mixed>}>
     */
    private function events(string $where): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT name, data FROM event_log WHERE '.$where.' ORDER BY id');

        return array_map(static function (array $row): array {
            $data = json_decode(\is_string($row['data']) ? $row['data'] : '', true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [\is_string($row['name']) ? $row['name'] : '', $data];
        }, $rows);
    }
}
