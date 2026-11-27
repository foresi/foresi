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
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\Priority;
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
        return 'Searches the tasks of the projects the person sees, subtasks included, newest first unless sort says otherwise. Without project_id, the tasks of archived projects are left out. '
            .'assignee "me" lists the tasks the person is responsible for, "none" those no one is responsible for; with finished false, those still to do. '
            .'priority, label_id, client_id and the created and updated dates narrow further, as the filters of the task list in Foresi do. '
            .'starred true lists My priorities, the tasks the person starred, and sort my_order puts them in the order the person gave them; nobody else sees whose star a task has. '
            .'view_id lists what a saved view holds, as the person sees it in Foresi: one of the list work.tasks, such as "work.tasks.mine", "work.tasks.priorities", "work.tasks.overdue", "work.tasks.unassigned", "work.tasks.high" or "work.tasks.no_due" (these leave out finished tasks unless they ask about the status type), or of work.my_tasks, such as "work.my_tasks.watching"; it needs the views:read scope. filter adds conditions in the format of foresi_list_lists, checked against the list of the view, work.tasks without one. '
            .'Returns {items, next_cursor, applied_view}: each task has its id, title, project, section_id, parent_id, status {id, name, type}, responsible {id, name}, due_on, priority, labels, subtask_count, done, private, starred, url, the page a person opens, and custom_fields by key; a filterable custom field is a field of filter by its key (see foresi_list_fields). '
            .'When next_cursor is not null, more tasks match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_task for the description, watchers and subtasks of one task.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text looked for in the title.'],
                'project_id' => ToolSchema::idSchema('Only the tasks of this project, an archived one too; find it with foresi_search_projects.'),
                'assignee' => ['type' => 'string', 'description' => 'Only the tasks this person is responsible for: "me", "none" for those no one is responsible for, or their id as foresi_list_project_people gives it.'],
                'priority' => ['type' => 'array', 'items' => ToolSchema::choiceSchema(array_column(Priority::cases(), 'value'), 'A priority.'), 'description' => 'Only the tasks of any of these priorities, such as ["high", "urgent"].'],
                'label_id' => ['type' => 'array', 'items' => ToolSchema::idSchema('A label id, as foresi_list_labels gives it.'), 'description' => 'Only the tasks with any of these labels.'],
                'client_id' => ToolSchema::idSchema('Only the tasks of the projects of this client; find it with foresi_search_contacts.'),
                'created_from' => ['type' => 'string', 'format' => 'date', 'description' => 'Only the tasks created on this day or later, YYYY-MM-DD.'],
                'created_to' => ['type' => 'string', 'format' => 'date', 'description' => 'Only the tasks created on this day or earlier.'],
                'updated_from' => ['type' => 'string', 'format' => 'date', 'description' => 'Only the tasks last changed on this day or later.'],
                'updated_to' => ['type' => 'string', 'format' => 'date', 'description' => 'Only the tasks last changed on this day or earlier.'],
                'sort' => ToolSchema::choiceSchema(array_merge(...array_map(static fn (string $sort): array => [$sort, '-'.$sort], TaskLists::SORTS)), 'The order: by due date (the undated last), priority, created, updated, project name, title or my_order, the order of My priorities (the unstarred last); a minus first for backwards, such as "-priority". Left out, the newest first.'),
                'status_type' => ToolSchema::choiceSchema(array_column(StatusType::cases(), 'value'), 'Only the tasks in a status of this type.'),
                'finished' => ['type' => 'boolean', 'description' => 'True for the tasks in a finished status (done or closed), false for the others; left out for both.'],
                'due' => ToolSchema::choiceSchema(['overdue', 'today', 'next_7_days', 'none'], 'Only the tasks due so: overdue and not finished, today, today or in the next seven days, or without a due date.'),
                'view_id' => ['type' => 'string', 'description' => 'Only the tasks a saved view of the list work.tasks or work.my_tasks holds: its id as foresi_list_views gives it, or a system key such as "work.tasks.overdue" or "work.tasks.priorities".'],
                'starred' => ['type' => 'boolean', 'description' => 'True for the tasks the person starred for My priorities (foresi_star_task), false for the others; left out for both.'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all tasks meet, {"conditions": [{"field", "op", "value"}]}, checked against the list of the view, work.tasks without one; its fields and operators are in foresi_list_lists.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $finished = $arguments['finished'] ?? null;
        $list = static fn (string $name): ?string => \is_array($arguments[$name] ?? null) && [] !== $arguments[$name] ? implode(',', array_map(static fn (mixed $one): string => \is_string($one) ? $one : '', $arguments[$name])) : null;
        $query = new TaskListQuery(
            ToolSchema::text($arguments, 'query') ?? '',
            ToolSchema::text($arguments, 'project_id'),
            ToolSchema::text($arguments, 'assignee'),
            ToolSchema::text($arguments, 'status_type'),
            null === $finished ? null : ToolSchema::flag($arguments, 'finished'),
            ToolSchema::text($arguments, 'due'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
            priority: $list('priority'),
            labelId: $list('label_id'),
            clientId: ToolSchema::text($arguments, 'client_id'),
            createdFrom: ToolSchema::text($arguments, 'created_from'),
            createdTo: ToolSchema::text($arguments, 'created_to'),
            updatedFrom: ToolSchema::text($arguments, 'updated_from'),
            updatedTo: ToolSchema::text($arguments, 'updated_to'),
            sort: ToolSchema::text($arguments, 'sort'),
            starred: \array_key_exists('starred', $arguments) ? ToolSchema::flag($arguments, 'starred') : null,
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        $viewId = ToolSchema::text($arguments, 'view_id');
        $page = $this->pages->read(fn (): \Foresi\Work\Infrastructure\Api\WorkPage => $this->lists->tasks($query, $viewId, $arguments['filter'] ?? null, $afterId), $viewId);

        return WorkToolPages::page($page, 'task', 'tasks');
    }
}
