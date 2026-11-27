<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Domain\Schedule\PeriodText;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\Task;

/**
 * A recurring task as the REST API and the MCP tools write it: the
 * RecurringTask and RecurringTaskDetail schemas.
 */
final readonly class RecurringTaskJson
{
    public function __construct(private ManageRecurringTasks $recurring)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(RecurringTask $recurring): array
    {
        return [
            'id' => $recurring->id(),
            'title' => $recurring->title(),
            'project' => ['id' => $recurring->project()->id(), 'name' => $recurring->project()->name()],
            'state' => $recurring->state()->value,
            'next_on' => $recurring->nextOccurrence()?->format('Y-m-d'),
            'created_count' => $recurring->createdCount(),
            'failing' => null !== $recurring->lastFailureCode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(RecurringTask $recurring): array
    {
        $recurrence = $recurring->recurrence();
        $section = $recurring->section();
        $responsible = $recurring->responsible();

        return self::item($recurring) + [
            'description' => $recurring->description(),
            'section' => $section instanceof \Foresi\Work\Domain\Section ? ['id' => $section->id(), 'name' => $section->name()] : null,
            'responsible' => $responsible instanceof \Foresi\Identity\Domain\Membership ? ['id' => $responsible->id(), 'name' => $responsible->user()->displayName()] : null,
            'priority' => $recurring->priority()->value,
            'private' => $recurring->isPrivate(),
            'estimate_minutes' => $recurring->estimateMinutes(),
            'label_ids' => $recurring->labelIds(),
            'watcher_ids' => $recurring->watcherIds(),
            'subtasks' => $recurring->subtasks(),
            'custom_fields' => [] === $recurring->customFields() ? new \stdClass() : $recurring->customFields(),
            'schedule' => [
                'first_on' => $recurrence->firstIssueOn()->format('Y-m-d'),
                'repeat' => $recurrence->unit()->value,
                'every' => $recurrence->every(),
                'last_day_of_month' => $recurrence->onLastDayOfMonth(),
                'end' => $recurrence->end()->value,
                'ends_on' => $recurrence->endOn()?->format('Y-m-d'),
                'ends_after' => $recurrence->endAfter(),
            ],
            'create_days_before' => $recurring->createDaysBefore(),
            'due_days_after' => $recurring->dueDaysAfter(),
            'skipped_on' => $recurring->skippedOn(),
            'last_on' => $recurring->lastOccurrence()?->format('Y-m-d'),
            'paused_at' => JsonValues::timestamp($recurring->pausedAt()),
            'ended_at' => JsonValues::timestamp($recurring->endedAt()),
            'last_failure' => null === $recurring->lastFailureCode() ? null : ['at' => JsonValues::timestamp($recurring->lastFailureAt()), 'code' => $recurring->lastFailureCode()],
            'next_tasks' => self::occurrences($recurring, ManageRecurringTasks::PREVIEW),
            'tasks' => array_map(static fn (Task $task): array => ['id' => $task->id(), 'name' => $task->title()], $this->recurring->tasks($recurring)),
            'created_at' => JsonValues::timestamp($recurring->createdAt()),
        ];
    }

    /**
     * The next tasks it creates, none while paused or ended.
     *
     * @return list<array{on: string, create_on: string, due_on: string, title: string}>
     */
    public static function occurrences(RecurringTask $recurring, int $count): array
    {
        if (RecurringTaskState::Active !== $recurring->state()) {
            return [];
        }

        return array_map(static fn (\DateTimeImmutable $on): array => [
            'on' => $on->format('Y-m-d'),
            'create_on' => $recurring->createOn($on)->format('Y-m-d'),
            'due_on' => $recurring->dueOn($on)->format('Y-m-d'),
            'title' => PeriodText::resolve($recurring->title(), $on),
        ], $recurring->upcoming($count));
    }
}
