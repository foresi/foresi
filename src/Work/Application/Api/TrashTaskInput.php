<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Moves a task to the trash over the API and MCP, as the task page does.
 */
#[Operation(
    name: 'tasks.trash',
    method: 'POST',
    path: '/api/v1/tasks/{taskId}/trash',
    tool: 'foresi_trash_task',
    scope: 'tasks:write',
    title: 'Trash a task',
    description: 'Moves a task to the trash with its subtasks, as the task page deletes it: it leaves every list, and it is purged after 30 days. Only whoever created it or an owner may (task_not_yours). Ask the person before you call it. Bring it back with foresi_restore_task.',
    destructive: true,
)]
final readonly class TrashTaskInput
{
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
    ) {
    }
}
