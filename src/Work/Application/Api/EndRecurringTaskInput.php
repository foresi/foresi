<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * End a recurring task over the API and MCP, as the header of its page does (ManageRecurringTasks).
 */
#[Operation(name: 'recurring-tasks.end', method: 'POST', path: '/api/v1/recurring-tasks/{recurringTaskId}/end', tool: 'foresi_end_recurring_task', scope: 'tasks:write', title: 'End a recurring task', description: 'Ends a recurring task for good: it creates no more tasks and cannot be resumed. The tasks it created stay. Repeatable. Ask the person first.', destructive: true, response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class EndRecurringTaskInput
{
    public function __construct(
        #[Field('The id of the recurring task.', format: 'uuid')]
        public string $recurringTaskId,
    ) {
    }
}
