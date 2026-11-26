<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Takes a task off the person's My priorities over the API and MCP (plan 37.2).
 */
#[Operation(
    name: 'tasks.unstar',
    method: 'DELETE',
    path: '/api/v1/tasks/{taskId}/star',
    tool: 'foresi_unstar_task',
    scope: 'tasks:write',
    title: 'Unstar a task',
    description: 'Takes the star of the person of the token off a task, so it leaves their My priorities; the task itself does not change. A task with no star stays as it is.',
    response: 'TaskDetail',
)]
final readonly class UnstarTaskInput
{
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
    ) {
    }
}
