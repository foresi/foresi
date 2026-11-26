<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Infrastructure\Api\TaskListQuery;
use Foresi\Work\Infrastructure\Api\WorkLists;

/**
 * The tasks the person sees, narrowed and paged as GET /api/v1/tasks does it,
 * each task as the API writes it.
 */
final readonly class SearchTasksTool implements ReadTool
{
    public function __construct(private WorkLists $lists, private ToolArguments $arguments, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_search_tasks';
    }

    public function title(): string
    {
        return 'Search tasks';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Searches the tasks of the projects the person sees, subtasks included, newest first. Without project_id, the tasks of archived projects are left out. '
            .'assignee "me" lists the tasks the person is responsible for: their tasks in this Foresi; with finished false, those still to do. '
            .'view_id lists what a saved view of the list work.my_tasks holds, such as "work.my_tasks.assigned" or "work.my_tasks.watching" (it needs the views:read scope), and filter adds conditions in the format of foresi_list_lists. '
            .'Returns {items, next_cursor, applied_view}: each task has its id, title, project, section_id, parent_id, status {id, name, type}, responsible {id, name}, due_on, priority, labels, subtask_count, done, private and url, the page a person opens. '
            .'Sort by due_on yourself; the order is by id. When next_cursor is not null, more tasks match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_task for the description, watchers and subtasks of one task.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text looked for in the title.'],
                'project_id' => ToolSchema::idSchema('Only the tasks of this project, an archived one too; find it with foresi_search_projects.'),
                'assignee' => ['type' => 'string', 'description' => 'Only the tasks this person is responsible for: "me", or their id as foresi_list_project_people gives it.'],
                'status_type' => ToolSchema::choiceSchema(array_column(StatusType::cases(), 'value'), 'Only the tasks in a status of this type.'),
                'finished' => ['type' => 'boolean', 'description' => 'True for the tasks in a finished status (done or closed), false for the others; left out for both.'],
                'due' => ToolSchema::choiceSchema(['overdue', 'today', 'next_7_days', 'none'], 'Only the tasks due so: overdue and not finished, today, today or in the next seven days, or without a due date.'),
                'view_id' => ['type' => 'string', 'description' => 'Only the tasks a saved view of the list work.my_tasks holds: its id as foresi_list_views gives it, or a system key such as "work.my_tasks.assigned".'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all tasks meet, {"conditions": [{"field", "op", "value"}]}, checked against the list work.my_tasks; its fields and operators are in foresi_list_lists.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $finished = $arguments['finished'] ?? null;
        $query = new TaskListQuery(
            ToolSchema::text($arguments, 'query') ?? '',
            ToolSchema::text($arguments, 'project_id'),
            ToolSchema::text($arguments, 'assignee'),
            ToolSchema::text($arguments, 'status_type'),
            null === $finished ? null : ToolSchema::flag($arguments, 'finished'),
            ToolSchema::text($arguments, 'due'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        $viewId = ToolSchema::text($arguments, 'view_id');
        $page = $this->pages->read(fn (): \Foresi\Work\Infrastructure\Api\WorkPage => $this->lists->tasks($query, $viewId, $arguments['filter'] ?? null, $afterId), $viewId);

        return WorkToolPages::page($page, 'task', 'tasks');
    }
}
