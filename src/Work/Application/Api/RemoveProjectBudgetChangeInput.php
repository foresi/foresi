<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Removes a wrong change of a project's time budget; the audit trail keeps it.
 */
#[Operation(
    name: 'work.project_budget.remove_change',
    method: 'DELETE',
    path: '/api/v1/projects/{projectId}/budget-changes/{changeId}',
    tool: 'foresi_remove_project_budget_change',
    scope: 'projects:write',
    title: 'Remove a change from a project budget',
    description: 'Removes a change entered by mistake from the time budget of a project; to lower a budget that was right, add a minus change instead. '
        .'Refused when the budget would go below zero (budget_below_zero). Only the owners and team members may, on a project that is not archived. Answers the budget as foresi_get_project_budget does.',
    destructive: true,
    response: 'ProjectBudget',
    staffOnly: true,
)]
final readonly class RemoveProjectBudgetChangeInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
        #[Field('The id of the change, as foresi_get_project_budget returns it.', format: 'uuid')]
        public string $changeId,
    ) {
    }
}
