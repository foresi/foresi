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
 * One task, as GET /api/v1/tasks/{id} writes it.
 */
final readonly class GetTaskTool implements ReadTool
{
    public function __construct(private WorkQueries $queries, private WorkToolPages $pages, private WorkJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_task';
    }

    public function title(): string
    {
        return 'Get a task';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Returns one task the person sees by its id: what foresi_search_tasks returns for it, with its description (Markdown), section, parent, watchers, subtasks, estimate_minutes, whether its project is archived, created_at, completed_at and custom_fields by key (see foresi_list_fields). A task in the trash, or a private one not shared with the person, is not found. Read its comments with foresi_list_task_comments.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['task_id' => ToolSchema::idSchema('The id of the task, as foresi_search_tasks returns it.')],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $task = $this->pages->read(fn (): \Foresi\Work\Application\TaskDetail => $this->queries->task(ToolSchema::text($arguments, 'task_id') ?? '') ?? throw new WorkRefused('unknown'));

        return new ToolOutput($this->json->taskDetail($task), 'The task with its description, watchers and subtasks.');
    }
}
