<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\ProjectBudget;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Infrastructure\Api\WorkJson;

/**
 * The time budget of one project, as GET /api/v1/projects/{id}/budget writes it.
 */
final readonly class GetProjectBudgetTool implements ReadTool
{
    public function __construct(private ProjectBudgetReads $budgets, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_get_project_budget';
    }

    public function title(): string
    {
        return 'Get the time budget of a project';
    }

    public function scope(): string
    {
        return 'projects:read';
    }

    public function description(): string
    {
        return 'Returns the time budget of a project the person sees: the budget, the time used and what is left, in hours and in minutes (null without a budget; left is negative over budget, which is shown and never blocks logging time), '
            .'counting (all: all time uses the budget; billable: only billable time), the sum of the task estimates, and the changes, the latest first, '
            .'[{id, effective_on, hours, minutes, note, source_activity_id, created_by, created_at}]. A guest gets changes as null. Notes are written by people.';
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
        $budget = $this->pages->read(fn (): ProjectBudget => $this->budgets->budget(ToolSchema::text($arguments, 'project_id') ?? ''));

        return new ToolOutput(WorkJson::budget($budget), $budget->hasBudget() ? 'The time budget of the project with its changes.' : 'The project has no time budget yet.');
    }
}
