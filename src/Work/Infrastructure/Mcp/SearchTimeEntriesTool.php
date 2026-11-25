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
use Foresi\Work\Infrastructure\Api\TimeEntryListQuery;
use Foresi\Work\Infrastructure\Api\WorkLists;

/**
 * The time the person sees, narrowed and paged as GET /api/v1/time-entries does it.
 */
final readonly class SearchTimeEntriesTool implements ReadTool
{
    public function __construct(private WorkLists $lists, private ToolArguments $arguments, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_search_time_entries';
    }

    public function title(): string
    {
        return 'Search time entries';
    }

    public function scope(): string
    {
        return 'time_entries:read';
    }

    public function description(): string
    {
        return 'Searches the time logged on the projects and tasks the person sees, newest first, a running timer included (its minutes are null and running is true). '
            .'Returns {items, next_cursor}: each entry has its id, project, task, person, worked_on, minutes, started_at, ended_at, note, billable, running and invoiced. '
            .'When next_cursor is not null, more entries match: call again with the same arguments and next_cursor as cursor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ToolSchema::idSchema('Only the time of this project.'),
                'task_id' => ToolSchema::idSchema('Only the time of this task.'),
                'person' => ['type' => 'string', 'description' => 'Only the time of this person: "me", or their id as foresi_list_project_people gives it.'],
                'from' => ToolSchema::dateSchema('Only the time worked on this day or later.'),
                'until' => ToolSchema::dateSchema('Only the time worked on this day or earlier.'),
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new TimeEntryListQuery(
            ToolSchema::text($arguments, 'project_id'),
            ToolSchema::text($arguments, 'task_id'),
            ToolSchema::text($arguments, 'person'),
            ToolSchema::text($arguments, 'from'),
            ToolSchema::text($arguments, 'until'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);

        return WorkToolPages::page($this->pages->read(fn (): \Foresi\Work\Infrastructure\Api\WorkPage => $this->lists->timeEntries($query, $afterId)), 'time entry', 'time entries', false);
    }
}
