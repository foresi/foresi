<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Doctrine\DBAL\Connection;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Domain\Priority;

/**
 * The events the use cases of tasks and comments record (ADR 0030, 0040): one
 * for each change that changes something, none for one that does not.
 */
final class TaskEventsTest extends WorkApiTestCase
{
    public function testCreatingATaskAndASubtaskRecordsTheirCreation(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Parent');
        $subtask = $this->as(fn (): string => $this->tasks()->create($project->id(), 'Child', parentId: $task)->id());

        self::assertSame([
            ['work.task.created', ['task_id' => $task, 'project_id' => $project->id(), 'parent_id' => null]],
            ['work.task.created', ['task_id' => $subtask, 'project_id' => $project->id(), 'parent_id' => $task]],
        ], $this->events());
    }

    public function testEachFieldChangedRecordsAnUpdateAndAnUnchangedOneNothing(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Draft');
        $section = $this->section($project, 'Design');
        $label = $this->label('Copy');
        $me = $this->membershipOf($this->owner)->id();
        $this->clear();

        $changes = [
            'title' => fn () => $this->tasks()->rename($task, 'Final'),
            'description' => fn () => $this->tasks()->describe($task, 'Text'),
            'responsible' => fn () => $this->tasks()->assign($task, $me),
            'due_on' => fn () => $this->tasks()->setDue($task, new \DateTimeImmutable('2026-11-30')),
            'priority' => fn () => $this->tasks()->prioritise($task, Priority::High),
            'section' => fn () => $this->tasks()->moveToSection($task, $section),
            'labels' => fn () => $this->tasks()->setLabels($task, [$label]),
        ];
        foreach ($changes as $field => $change) {
            $this->as($change);
            self::assertSame([['work.task.updated', ['task_id' => $task, 'project_id' => $project->id(), 'changed' => [$field], 'custom_fields' => []]]], $this->events(), $field);
            $this->clear();
            // The same value again changes nothing.
            $this->as($change);
            self::assertSame([], $this->events(), $field.' again');
        }

        $this->as(fn () => $this->tasks()->setLabels($task, []));
        self::assertSame(['labels'], $this->events()[0][1]['changed'] ?? null);
    }

    public function testFinishingATaskRecordsItsCompletionOnce(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Ship');
        [$open, $active, $done] = $this->statuses($project);
        $this->clear();

        $this->as(fn () => $this->tasks()->changeStatus($task, $active->id()));
        self::assertSame(['work.task.updated'], array_column($this->events(), 0));
        $this->clear();

        $this->as(fn () => $this->tasks()->changeStatus($task, $done->id()));
        self::assertSame([
            ['work.task.updated', ['task_id' => $task, 'project_id' => $project->id(), 'changed' => ['status'], 'custom_fields' => []]],
            ['work.task.completed', ['task_id' => $task, 'project_id' => $project->id()]],
        ], $this->events());
        $this->clear();

        // Moving it on the board within finished statuses, or back, completes nothing.
        $this->as(fn () => $this->tasks()->move($task, $open->id()));
        self::assertSame(['work.task.updated'], array_column($this->events(), 0));
    }

    public function testTrashingRecordsTheTaskNotItsSubtasks(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Parent');
        $this->as(fn (): \Foresi\Work\Domain\Task => $this->tasks()->create($project->id(), 'Child', parentId: $task));
        $this->clear();

        $this->as(fn (): \Foresi\Work\Domain\Task => $this->tasks()->delete($task));

        self::assertSame([['work.task.trashed', ['task_id' => $task, 'project_id' => $project->id()]]], $this->events());
    }

    public function testACommentRecordsItsAdditionAndWhetherItIsInternal(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Discuss');
        $this->clear();

        $comment = $this->as(fn (): string => self::getContainer()->get(ManageComments::class)->add($task, 'Between us', true)->id());

        self::assertSame([['work.comment.added', ['comment_id' => $comment, 'task_id' => $task, 'project_id' => $project->id(), 'internal' => true]]], $this->events());
    }

    private function tasks(): ManageTasks
    {
        return $this->service(ManageTasks::class);
    }

    /**
     * @return list<array{string, array<mixed>}> the events of Work, in the order recorded
     */
    private function events(): array
    {
        $rows = $this->db()->fetchAllAssociative("SELECT name, data FROM event_log WHERE name LIKE 'work.%' ORDER BY id");

        return array_map(static function (array $row): array {
            $data = json_decode(\is_string($row['data']) ? $row['data'] : '', true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [\is_string($row['name']) ? $row['name'] : '', $data];
        }, $rows);
    }

    private function clear(): void
    {
        $this->db()->executeStatement('DELETE FROM event_log');
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
