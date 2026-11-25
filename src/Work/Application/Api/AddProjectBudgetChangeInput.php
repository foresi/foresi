<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * A dated change of a project's time budget over the API and MCP, such as
 * "+8 h approved" or "-2 h": the budget is the sum of the changes.
 */
#[Operation(
    name: 'work.project_budget.add_change',
    method: 'POST',
    path: '/api/v1/projects/{projectId}/budget-changes',
    tool: 'foresi_add_project_budget_change',
    scope: 'projects:write',
    title: 'Add a change to a project budget',
    description: 'Adds hours to the time budget of a project, or takes them away with a minus: the budget is the sum of its changes, and a project without any has none. '
        .'Give hours, a decimal number with at most two decimal places such as "8" or "-2.5", or minutes, a whole number, not both (hours_invalid; zero is hours_zero). '
        .'The budget never goes below zero (budget_below_zero); time logged over it is shown, never refused. effective_on, the date in the change log, is today when left out; every change counts in the budget whatever its date. '
        .'source_activity_id names the activity the change comes from, such as an e-mail, which must be linked to the project (source_not_on_project). '
        .'Only the owners and team members may, on a project that is not archived. Answers the budget as foresi_get_project_budget does.',
    status: 201,
    response: 'ProjectBudget',
    staffOnly: true,
)]
final readonly class AddProjectBudgetChangeInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
        #[Field('The hours, plus or minus, as a decimal number in a string with at most two decimal places, such as "8" or "-2.5".', maxLength: 12)]
        public ?string $hours = null,
        #[Field('The minutes, plus or minus, in place of hours.')]
        public ?int $minutes = null,
        #[Field('The date of the change in the change log, YYYY-MM-DD, such as the day the hours were approved; today when left out. The budget is the sum of all changes, whatever their date.', format: 'date')]
        public ?string $effectiveOn = null,
        #[Field('Why, such as who approved the hours.', maxLength: ProjectBudgetChange::MAX_NOTE_LENGTH)]
        public string $note = '',
        #[Field('The activity the change comes from, such as the e-mail that approved it; it must be linked to the project.', format: 'uuid')]
        public ?string $sourceActivityId = null,
    ) {
    }
}
