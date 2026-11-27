<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Timesheet\Application\BudgetChangeRow;
use Foresi\Timesheet\Application\ProjectBudget;
use Foresi\Timesheet\Application\TimeEntryRow;

/**
 * Time and the time budget of a project as the REST API and the MCP tools
 * write them. A person is their membership id and name, as Work's tools name
 * people. Hourly rates stay on the screens.
 */
final readonly class TimesheetJson
{
    /**
     * The time budget of a project with its change log; the changes are null for a guest.
     *
     * @return array<string, mixed>
     */
    public static function budget(ProjectBudget $budget): array
    {
        return [
            'project_id' => $budget->projectId,
            'hours' => self::hours($budget->budgetMinutes),
            'used_hours' => self::hours($budget->usedMinutes),
            'left_hours' => self::hours($budget->leftMinutes()),
            'counting' => $budget->counting->value,
            'estimate_hours' => self::hours($budget->estimateMinutes),
            'minutes' => $budget->budgetMinutes,
            'used_minutes' => $budget->usedMinutes,
            'left_minutes' => $budget->leftMinutes(),
            'over_budget' => $budget->isOver(),
            'estimate_minutes' => $budget->estimateMinutes,
            'changes' => null === $budget->changes ? null : array_map(static fn (BudgetChangeRow $change): array => [
                'id' => $change->id,
                'effective_on' => JsonValues::date($change->effectiveOn),
                'hours' => self::hours($change->minutes),
                'minutes' => $change->minutes,
                'note' => $change->note,
                'source_activity_id' => $change->sourceActivityId,
                'created_by' => ['id' => $change->createdBy, 'name' => $change->authorName],
                'created_at' => JsonValues::timestamp($change->createdAt),
            ], $budget->changes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function timeEntry(TimeEntryRow $entry): array
    {
        return [
            'id' => $entry->id,
            'project' => ['id' => $entry->projectId, 'name' => $entry->projectName],
            'task' => null === $entry->taskId ? null : ['id' => $entry->taskId, 'title' => $entry->taskTitle],
            'person' => ['id' => $entry->membershipId, 'name' => $entry->personName],
            'worked_on' => JsonValues::date($entry->workedOn),
            'minutes' => $entry->minutes,
            'started_at' => JsonValues::timestamp($entry->startedAt),
            'ended_at' => JsonValues::timestamp($entry->endedAt),
            'note' => $entry->note,
            'billable' => $entry->billable,
            'running' => $entry->running,
            'invoiced' => $entry->invoiced,
        ];
    }

    /**
     * @return ($minutes is int ? float : null)
     */
    private static function hours(?int $minutes): ?float
    {
        return null === $minutes ? null : round($minutes / 60, 2);
    }
}
