<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Infrastructure\Api\RecurringTaskJson;

/**
 * The next tasks of a recurring task, as GET /api/v1/recurring-tasks/{id}/preview lists them.
 */
final readonly class PreviewRecurringTaskTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ManageRecurringTasks $recurring, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_preview_recurring_task';
    }

    public function title(): string
    {
        return 'Preview a recurring task';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Lists the next tasks a recurring task creates: for each, on (its date in the schedule), create_on (the day it is created), due_on and the title with the period variables of its date. Skipped dates are left out; a paused or ended one lists none. '
            .'Use it to check a schedule with the person after foresi_create_recurring_task or foresi_update_recurring_task, and to find a date for foresi_skip_recurring_task_occurrence. count sets how many, 1 to 24 (5 by default).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recurring_task_id' => ToolSchema::idSchema('The id of the recurring task, as foresi_list_recurring_tasks returns it.'),
                'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ManageRecurringTasks::MAX_PREVIEW, 'description' => 'How many next tasks; 5 by default.'],
            ],
            'required' => ['recurring_task_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $count = $arguments['count'] ?? ManageRecurringTasks::PREVIEW;
        if (!\is_int($count) || $count < 1 || $count > ManageRecurringTasks::MAX_PREVIEW) {
            throw new ToolRefusal(ToolRefusal::INVALID_ARGUMENT, 'count is 1 to 24.');
        }
        $recurring = $this->pages->read(fn (): RecurringTask => $this->recurring->get(ToolSchema::text($arguments, 'recurring_task_id') ?? ''));
        $next = RecurringTaskJson::occurrences($recurring, $count);

        return new ToolOutput(['data' => $next], [] === $next ? 'It creates no task: it is paused or ended.' : \sprintf('The next %d tasks.', \count($next)));
    }

    public function staffOnly(): bool
    {
        return true;
    }
}
