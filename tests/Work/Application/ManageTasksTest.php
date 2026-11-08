<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Work\Application\Choice;
use App\Work\Application\StatusTasks;
use App\Work\Application\TaskGroup;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\TaskWatcher;

final class ManageTasksTest extends WorkTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->project();
    }

    public function testASubtaskGoesOneLevelDeepOnly(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $subtask = $this->tasks->create($this->project->id(), 'Check the links', parentId: $task->id());

        $this->expectExceptionObject(new WorkRefused('parent'));
        $this->tasks->create($this->project->id(), 'Too deep', parentId: $subtask->id());
    }

    public function testDroppingATaskOnTheBoardRenumbersAndSticks(): void
    {
        [$toDo, $inProgress] = $this->statuses($this->project);
        $first = $this->tasks->create($this->project->id(), 'First');
        $second = $this->tasks->create($this->project->id(), 'Second');
        $third = $this->tasks->create($this->project->id(), 'Third');
        $this->tasks->create($this->project->id(), 'Started', statusId: $inProgress->id());

        $this->tasks->move($third->id(), $toDo->id(), $first->id());
        $this->tasks->move($first->id(), $inProgress->id());

        self::assertSame([['Third', 'Second'], ['Started', 'First'], []], $this->columns());
        self::assertSame([0, 1, 2, 3], array_map(static fn (Task $task): int => $task->position(), $this->em->getRepository(Task::class)->findBy([], ['position' => 'ASC'])));
        self::assertSame(1, $second->position());
    }

    public function testTheCreatorAndTheResponsibleWatch(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Write the brief', responsibleId: $this->member->id());
        $this->tasks->assign($task->id(), $this->owner->id());
        $this->tasks->watch($task->id(), $this->member->id());

        $detail = $this->queries->task($task->id());
        self::assertNotNull($detail);
        self::assertSame(['Marek Member', 'Olga Owner'], array_map(static fn (Choice $watcher): string => $watcher->name, $detail->watchers));
        self::assertCount(2, $this->em->getRepository(TaskWatcher::class)->findAll());
    }

    public function testAnInactivePersonIsNotResponsible(): void
    {
        $this->member->deactivate($this->clock->now());
        $this->em->flush();

        $this->expectExceptionObject(new WorkRefused('responsible'));
        $this->tasks->create($this->project->id(), 'Write the brief', responsibleId: $this->member->id());
    }

    public function testMyTasksAreGroupedByTheDayOfTheTenant(): void
    {
        // 23:30 in UTC is already the 11th in Prague.
        $this->clock->modify('2026-11-10 23:30:00');
        $done = $this->statuses($this->project)[2];
        $mine = fn (string $title, ?string $due): Task => $this->tasks->create($this->project->id(), $title, responsibleId: $this->owner->id(), dueOn: null === $due ? null : new \DateTimeImmutable($due));
        $mine('Yesterday in Prague', '2026-11-10');
        $mine('Today in Prague', '2026-11-11');
        $mine('Next week', '2026-11-18');
        $mine('Later on', '2026-11-19');
        $mine('Some day', null);
        $finished = $mine('Finished', '2026-11-01');
        $this->tasks->changeStatus($finished->id(), $done->id());
        $this->tasks->create($this->project->id(), 'Not mine', responsibleId: $this->member->id(), dueOn: new \DateTimeImmutable('2026-11-11'));

        self::assertSame([
            TaskGroup::OVERDUE => ['Yesterday in Prague'],
            TaskGroup::TODAY => ['Today in Prague'],
            TaskGroup::UPCOMING => ['Next week'],
            TaskGroup::LATER => ['Later on'],
            TaskGroup::NO_DATE => ['Some day'],
        ], array_merge(...array_map(static fn (TaskGroup $group): array => [$group->key => array_map(static fn (TaskRow $row): string => $row->title, $group->tasks)], $this->queries->myTasks())));
        // The creator follows every task created, the one of another person too.
        self::assertCount(6, $this->queries->watchedTasks());
    }

    /**
     * @return list<list<string>>
     */
    private function columns(): array
    {
        $board = $this->queries->board($this->project->id());
        self::assertNotNull($board);

        return array_map(static fn (StatusTasks $column): array => array_map(static fn (TaskRow $row): string => $row->title, $column->tasks), $board->columns);
    }
}
