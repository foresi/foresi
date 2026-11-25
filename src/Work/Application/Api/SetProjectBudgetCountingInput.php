<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Which time of a project uses its time budget.
 */
#[Operation(
    name: 'work.project_budget.set_counting',
    method: 'PUT',
    path: '/api/v1/projects/{projectId}/budget-counting',
    tool: 'foresi_set_project_budget_counting',
    scope: 'projects:write',
    title: 'Choose which time uses a project budget',
    description: 'Chooses which time of a project uses its time budget: all, billable and non-billable time (the default), or billable, the billable time only, so that work logged non-billable leaves the budget alone. '
        .'Only the owners and team members may, on a project that is not archived. Answers the budget as foresi_get_project_budget does.',
    response: 'ProjectBudget',
    staffOnly: true,
)]
final readonly class SetProjectBudgetCountingInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
        #[Field('all or billable.', enum: ['all', 'billable'])]
        public string $counting,
    ) {
    }
}
