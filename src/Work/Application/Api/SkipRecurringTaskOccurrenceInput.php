<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Skip one date of a recurring task over the API and MCP, as the header of its page does (ManageRecurringTasks).
 */
#[Operation(name: 'recurring-tasks.skip', method: 'POST', path: '/api/v1/recurring-tasks/{recurringTaskId}/skip', tool: 'foresi_skip_recurring_task_occurrence', scope: 'tasks:write', title: 'Skip one date of a recurring task', description: 'Leaves out the task of one date of a recurring task, as a holiday week: the date must be one it falls on (foresi_preview_recurring_task lists them, not_an_occurrence otherwise) whose task is not created yet (occurrence_created). A skipped date does not count to an end after a number of tasks. Skipping it twice changes nothing.', destructive: false, response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class SkipRecurringTaskOccurrenceInput
{
    public function __construct(
        #[Field('The id of the recurring task.', format: 'uuid')]
        public string $recurringTaskId,
        #[Field('The date to skip, YYYY-MM-DD.', format: 'date')]
        public string $on,
    ) {
    }
}
