<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Audit\Application\AuditCorrelation;
use App\Work\Application\TaskHistory;
use App\Work\Application\TaskHistoryEntry;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\Task;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class TaskHistoryTest extends WorkTestCase
{
    public function testEachActionIsOneStepToldByName(): void
    {
        $project = $this->project();
        [, $inProgress] = $this->statuses($project);
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $task = $this->tasks->create($project->id(), 'Write the brief');
        $other = $this->tasks->create($project->id(), 'Other');
        $this->clock->sleep(60);
        $this->tasks->rename($task->id(), 'Write the short brief');
        $this->tasks->setDue($task->id(), new \DateTimeImmutable('2026-11-20'));
        $this->tasks->prioritise($task->id(), Priority::High);
        $this->tasks->setDue($task->id(), null);
        $this->tasks->move($task->id(), $inProgress->id());
        $this->tasks->move($other->id(), $inProgress->id(), $task->id());
        $this->tasks->assign($task->id(), $this->member->id());
        $this->tasks->setLabels($task->id(), [$urgent->id()]);
        $this->tasks->unwatch($task->id(), $this->owner->id());
        $this->labels->delete($urgent->id());

        $history = self::getContainer()->get(TaskHistory::class)->of($task->id());

        self::assertSame(
            [TaskHistoryEntry::CREATED, ...array_fill(0, 9, TaskHistoryEntry::CHANGED)],
            array_map(static fn (TaskHistoryEntry $entry): string => $entry->event, $history),
        );
        self::assertSame('Olga Owner', $history[0]->userName);
        self::assertSame(['work.task.field.title'], $history[1]->fields);
        // The due date and the priority tell what they were and what they became.
        self::assertSame([[], true, null, '2026-11-20', null], [$history[2]->fields, $history[2]->dueChanged, $history[2]->dueFrom, $history[2]->dueTo?->format('Y-m-d'), $history[2]->priorityFrom]);
        self::assertSame([[], false, Priority::None, Priority::High], [$history[3]->fields, $history[3]->dueChanged, $history[3]->priorityFrom, $history[3]->priorityTo]);
        self::assertSame([true, '2026-11-20', null], [$history[4]->dueChanged, $history[4]->dueFrom?->format('Y-m-d'), $history[4]->dueTo]);
        self::assertSame([true, 'K udělání', 'Probíhá'], [$history[5]->statusChanged, $history[5]->statusFrom, $history[5]->statusTo]);
        self::assertSame([true, null, 'Marek Member', ['Marek Member']], [$history[6]->reassigned, $history[6]->assignedFrom, $history[6]->assignedTo, $history[6]->watchersAdded]);
        self::assertSame(['Urgent'], $history[7]->labelsAdded);
        self::assertSame(['Olga Owner'], $history[8]->watchersRemoved);
        self::assertSame(['Urgent'], $history[9]->labelsRemoved);
        self::assertSame('2026-11-10 09:01', $history[1]->occurredAt->format('Y-m-d H:i'));
    }

    public function testAStepThatOnlyMovedTheTaskShowsNothing(): void
    {
        $project = $this->project();
        [$toDo] = $this->statuses($project);
        $first = $this->tasks->create($project->id(), 'First');
        $this->tasks->create($project->id(), 'Second');
        $third = $this->tasks->create($project->id(), 'Third');
        // The first two make room for the third.
        $this->tasks->move($third->id(), $toDo->id(), $first->id());
        $this->tasks->assign($first->id(), $this->member->id());
        $this->tasks->assign($first->id(), null);

        $history = $this->history($first->id());

        self::assertSame([TaskHistoryEntry::CREATED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED], array_map(static fn (TaskHistoryEntry $entry): string => $entry->event, $history));
        self::assertSame([false, false, false, []], [$history[0]->statusChanged, $history[0]->reassigned, $history[0]->dueChanged, $history[0]->fields]);
        self::assertSame([true, null, 'Marek Member'], [$history[1]->reassigned, $history[1]->assignedFrom, $history[1]->assignedTo]);
        self::assertSame([true, 'Marek Member', null], [$history[2]->reassigned, $history[2]->assignedFrom, $history[2]->assignedTo]);
    }

    public function testAFieldChangedSeveralTimesInOneStepIsToldOnce(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Draft');

        self::getContainer()->get(AuditCorrelation::class)->within('edit', function () use ($task): void {
            $this->tasks->rename($task->id(), 'First draft');
            $this->tasks->rename($task->id(), 'Second draft');
            $this->tasks->describe($task->id(), 'What to write');
        });

        $history = $this->history($task->id());
        self::assertCount(2, $history);
        self::assertSame(['work.task.field.title', 'work.task.field.description'], $history[1]->fields);
    }

    public function testAStatusRemovedSinceKeepsItsLastName(): void
    {
        $project = $this->project();
        [, $inProgress, $done] = $this->statuses($project);
        $task = $this->tasks->create($project->id(), 'Write the brief');
        $this->tasks->changeStatus($task->id(), $inProgress->id());
        $this->workflow->remove($inProgress->id(), $done->id());

        $history = $this->history($task->id());

        self::assertSame(['K udělání', 'Probíhá'], [$history[1]->statusFrom, $history[1]->statusTo]);
        self::assertSame(['Probíhá', 'Hotovo'], [$history[2]->statusFrom, $history[2]->statusTo]);
    }

    public function testAStepOfNobodyHasNoUserName(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $this->em->find(Task::class, $task->id())?->rename('Write the short brief');
        $this->em->flush();
        $this->signIn($this->owner);

        $history = $this->history($task->id());

        self::assertSame(['Olga Owner', null], [$history[0]->userName, $history[1]->userName]);
    }

    /**
     * @return list<TaskHistoryEntry>
     */
    private function history(string $taskId): array
    {
        return self::getContainer()->get(TaskHistory::class)->of($taskId);
    }
}
