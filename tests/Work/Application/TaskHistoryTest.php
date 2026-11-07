<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Work\Application\TaskHistory;
use App\Work\Application\TaskHistoryEntry;
use App\Work\Domain\LabelColour;

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
        $this->tasks->move($task->id(), $inProgress->id());
        $this->tasks->move($other->id(), $inProgress->id(), $task->id());
        $this->tasks->assign($task->id(), $this->member->id());
        $this->tasks->setLabels($task->id(), [$urgent->id()]);
        $this->tasks->unwatch($task->id(), $this->owner->id());
        $this->labels->delete($urgent->id());

        $history = self::getContainer()->get(TaskHistory::class)->of($task->id());

        self::assertSame(
            [TaskHistoryEntry::CREATED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED, TaskHistoryEntry::CHANGED],
            array_map(static fn (TaskHistoryEntry $entry): string => $entry->event, $history),
        );
        self::assertSame('Olga Owner', $history[0]->userName);
        self::assertSame(['work.task.field.title'], $history[1]->fields);
        self::assertSame(['work.task.field.due_on'], $history[2]->fields);
        self::assertSame([true, 'K udělání', 'Probíhá'], [$history[3]->statusChanged, $history[3]->statusFrom, $history[3]->statusTo]);
        self::assertSame([true, null, 'Marek Member', ['Marek Member']], [$history[4]->reassigned, $history[4]->assignedFrom, $history[4]->assignedTo, $history[4]->watchersAdded]);
        self::assertSame(['Urgent'], $history[5]->labelsAdded);
        self::assertSame(['Olga Owner'], $history[6]->watchersRemoved);
        self::assertSame(['Urgent'], $history[7]->labelsRemoved);
        self::assertSame('2026-11-10 09:01', $history[1]->occurredAt->format('Y-m-d H:i'));
    }
}
