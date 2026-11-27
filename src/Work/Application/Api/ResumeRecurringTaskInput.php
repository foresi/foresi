<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Resume a paused recurring task over the API and MCP, as the header of its page does (ManageRecurringTasks).
 */
#[Operation(name: 'recurring-tasks.resume', method: 'POST', path: '/api/v1/recurring-tasks/{recurringTaskId}/resume', tool: 'foresi_resume_recurring_task', scope: 'tasks:write', title: 'Resume a recurring task', description: 'Resumes a paused recurring task from the first date on or after today; the dates of the pause are not created. A task due today is created at once. Resuming an active one changes nothing; an ended one stays ended (recurring_ended).', destructive: false, response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class ResumeRecurringTaskInput
{
    public function __construct(
        #[Field('The id of the recurring task.', format: 'uuid')]
        public string $recurringTaskId,
    ) {
    }
}
