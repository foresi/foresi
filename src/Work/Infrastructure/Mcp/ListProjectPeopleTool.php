<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Api\WorkJson;

/**
 * Who may be responsible for a task of a project, as GET /api/v1/projects/{id}/people answers it.
 */
final readonly class ListProjectPeopleTool implements ReadTool
{
    public function __construct(private WorkQueries $queries, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_list_project_people';
    }

    public function title(): string
    {
        return 'List the people of a project';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Returns the active people who may be responsible for a task of the project, by name: those who see all of it that is not private. With task_id, those who see that task of the project, which a private task narrows. Each is {id, name}; the id is the responsible_id of foresi_create_task and foresi_update_task, the assignee of foresi_search_tasks and the person of foresi_search_time_entries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ToolSchema::idSchema('The id of the project, as foresi_search_projects returns it.'),
                'task_id' => ToolSchema::idSchema('A task of the project, to list only who sees it.'),
            ],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $people = $this->pages->read(fn (): array => $this->queries->projectPeople(ToolSchema::text($arguments, 'project_id') ?? '', ToolSchema::text($arguments, 'task_id')) ?? throw new WorkRefused('unknown'));
        $count = \count($people);

        return new ToolOutput(['items' => array_map(WorkJson::person(...), $people)], 1 === $count ? '1 person.' : \sprintf('%d people.', $count));
    }
}
