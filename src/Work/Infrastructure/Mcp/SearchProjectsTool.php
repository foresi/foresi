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
use Foresi\Work\Infrastructure\Api\ProjectListQuery;
use Foresi\Work\Infrastructure\Api\WorkLists;

/**
 * The projects the person sees, narrowed and paged as GET /api/v1/projects does it.
 */
final readonly class SearchProjectsTool implements ReadTool
{
    public function __construct(private WorkLists $lists, private ToolArguments $arguments, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_search_projects';
    }

    public function title(): string
    {
        return 'Search projects';
    }

    public function scope(): string
    {
        return 'projects:read';
    }

    public function description(): string
    {
        return 'Searches the projects the person sees, newest first; archived ones are left out unless archived is true. '
            .'view_id lists what a saved view of the list work.projects holds (it needs the views:read scope), and filter adds conditions in the format of foresi_list_lists. '
            .'Returns {items, next_cursor, applied_view}: each project has its id, name, client {id, name}, visibility, workflow, open_tasks, overdue_tasks, unassigned_tasks (counts of the unfinished tasks the person sees, subtasks included), nearest_due_on, whether it is archived, and custom_fields by key; a filterable custom field is a field of filter by its key (see foresi_list_fields). '
            .'When next_cursor is not null, more projects match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_project for its statuses and sections, which a new or changed task needs.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text looked for in the name.'],
                'archived' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the archived projects, false only those that are not.'],
                'client_id' => ToolSchema::idSchema('Only the projects for this client, a contact as foresi_search_contacts returns it.'),
                'view_id' => ['type' => 'string', 'description' => 'Only the projects a saved view of the list work.projects holds: its id as foresi_list_views gives it, or a system key such as "work.projects.archived".'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all projects meet, {"conditions": [{"field", "op", "value"}]}, checked against the list work.projects; its fields and operators are in foresi_list_lists; open_tasks, overdue_tasks, unassigned_tasks (numbers) and next_due (a date) filter on the counts, e.g. {"field": "overdue_tasks", "op": "gt", "value": 0}.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new ProjectListQuery(
            ToolSchema::text($arguments, 'query') ?? '',
            ToolSchema::flag($arguments, 'archived'),
            ToolSchema::text($arguments, 'client_id'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        $viewId = ToolSchema::text($arguments, 'view_id');
        $page = $this->pages->read(fn (): \Foresi\Work\Infrastructure\Api\WorkPage => $this->lists->projects($query, $viewId, $arguments['filter'] ?? null, $afterId), $viewId);

        return WorkToolPages::page($page, 'project', 'projects');
    }
}
