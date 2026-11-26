<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Api\WorkJson;

/**
 * One project, as GET /api/v1/projects/{id} writes it.
 */
final readonly class GetProjectTool implements ReadTool
{
    public function __construct(private WorkQueries $queries, private WorkToolPages $pages, private WorkJson $json, private ProjectBudgetReads $budgets)
    {
    }

    public function name(): string
    {
        return 'foresi_get_project';
    }

    public function title(): string
    {
        return 'Get a project';
    }

    public function scope(): string
    {
        return 'projects:read';
    }

    public function description(): string
    {
        return 'Returns one project the person sees by its id, an archived one too: what foresi_search_projects returns for it, with its description, whether new time on it is billable, its statuses [{id, name, type, position}] in order and the sections [{id, name, position}] the person sees, and its time budget {hours, used_hours, left_hours, counting, estimate_hours} in hours, or null without one; left_hours is negative over budget, and counting says whether all time or only billable time uses it (foresi_get_project_budget has the changes). A task changes status by the id of one of these statuses: one of type done or closed completes it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['project_id' => ToolSchema::idSchema('The id of the project, as foresi_search_projects returns it.')],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $project = $this->pages->read(fn (): \Foresi\Work\Application\ProjectDetail => $this->queries->project(ToolSchema::text($arguments, 'project_id') ?? '') ?? throw new WorkRefused('unknown'));

        return new ToolOutput($this->json->projectDetail($project, $this->budgets->find($project->row->id)), 'The project with its statuses, sections and budget.');
    }
}
