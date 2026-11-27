<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Pause a recurring task over the API and MCP, as the header of its page does (ManageRecurringTasks).
 */
#[Operation(name: 'recurring-tasks.pause', method: 'POST', path: '/api/v1/recurring-tasks/{recurringTaskId}/pause', tool: 'foresi_pause_recurring_task', scope: 'tasks:write', title: 'Pause a recurring task', description: 'Pauses a recurring task: it creates no task until it is resumed, and the dates that pass meanwhile are skipped, not created later. Repeatable. An ended one cannot pause (recurring_ended).', destructive: false, response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class PauseRecurringTaskInput
{
    public function __construct(
        #[Field('The id of the recurring task.', format: 'uuid')]
        public string $recurringTaskId,
    ) {
    }
}
