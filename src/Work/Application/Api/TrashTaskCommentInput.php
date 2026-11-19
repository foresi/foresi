<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Deletes a comment over the API and MCP, as the discussion of the task does.
 */
#[Operation(
    name: 'task_comments.trash',
    method: 'POST',
    path: '/api/v1/task-comments/{commentId}/trash',
    tool: 'foresi_trash_task_comment',
    scope: 'tasks:write',
    title: 'Delete a comment',
    description: 'Deletes a comment: its author and date stay in the discussion without its text, which its author or an owner can restore on the screens for 30 days. Only its author or an owner may (comment_not_yours). Ask the person before you call it.',
    destructive: true,
)]
final readonly class TrashTaskCommentInput
{
    public function __construct(
        #[Field('The id of the comment, as foresi_list_task_comments gives it.', format: 'uuid')]
        public string $commentId,
    ) {
    }
}
