<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Takes a task out of the trash over the API and MCP.
 */
#[Operation(
    name: 'tasks.restore',
    method: 'POST',
    path: '/api/v1/tasks/{taskId}/restore',
    tool: 'foresi_restore_task',
    scope: 'tasks:write',
    title: 'Restore a task',
    description: 'Takes a task out of the trash with the subtasks that went there with it; a subtask brings its task back too. Only whoever created it or an owner may (task_not_yours). Restoring a task out of the trash changes nothing.',
    response: 'TaskDetail',
    staffOnly: true,
)]
final readonly class RestoreTaskInput
{
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
    ) {
    }
}
