<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Infrastructure\Api\RecurringTaskJson;

/**
 * One recurring task, as GET /api/v1/recurring-tasks/{id} writes it.
 */
final readonly class GetRecurringTaskTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ManageRecurringTasks $recurring, private WorkToolPages $pages, private RecurringTaskJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_recurring_task';
    }

    public function title(): string
    {
        return 'Get a recurring task';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Returns one recurring task by its id: what each of its tasks gets (description, section, responsible, priority, private, estimate_minutes, label_ids, watcher_ids, subtasks, custom_fields), its schedule, create_days_before and due_days_after (null: due at the end of its period), skipped_on, '
            .'last_failure when the last run could not create its task (archived: the project is archived; custom_fields: a value its field no longer takes), next_tasks, the next five with the day each is created, its due date and title, and tasks, the last ten it created.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['recurring_task_id' => ToolSchema::idSchema('The id of the recurring task, as foresi_list_recurring_tasks returns it.')],
            'required' => ['recurring_task_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $recurring = $this->pages->read(fn (): RecurringTask => $this->recurring->get(ToolSchema::text($arguments, 'recurring_task_id') ?? ''));

        return new ToolOutput($this->json->detail($recurring), 'The recurring task with its schedule and next tasks.');
    }

    public function staffOnly(): bool
    {
        return true;
    }
}
