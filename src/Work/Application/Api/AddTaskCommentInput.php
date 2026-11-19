<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;
use Foresi\Work\Domain\TaskComment;

/**
 * A comment on a task over the API and MCP, as the discussion of the task adds one.
 */
#[Operation(
    name: 'tasks.comment',
    method: 'POST',
    path: '/api/v1/tasks/{taskId}/comments',
    tool: 'foresi_add_task_comment',
    scope: 'tasks:write',
    title: 'Comment on a task',
    description: 'Adds a comment in Markdown to a task the person sees. Its author follows the task and its watchers are told; @mentions in the form of the screens notify who they name. '
        .'internal true keeps it to the people of the organisation, never shown to the guests the task is shared with.',
    status: 201,
    response: 'TaskComment',
)]
final readonly class AddTaskCommentInput
{
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
        #[Field('The comment, in Markdown.', maxLength: TaskComment::MAX_BODY_LENGTH)]
        public string $body,
        #[Field('Whether only the people of the organisation read it.')]
        public bool $internal = false,
    ) {
    }
}
