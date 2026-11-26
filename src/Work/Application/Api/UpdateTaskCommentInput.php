<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\TaskComment;

/**
 * A new text of a comment over the API and MCP, by its author.
 */
#[Operation(
    name: 'task_comments.update',
    method: 'PATCH',
    path: '/api/v1/task-comments/{commentId}',
    tool: 'foresi_update_task_comment',
    scope: 'tasks:write',
    title: 'Edit a comment',
    description: 'Replaces the text of a comment; only its author may (comment_not_yours), and a deleted comment is not edited. People newly @mentioned are told.',
    response: 'TaskComment',
)]
final readonly class UpdateTaskCommentInput
{
    public function __construct(
        #[Field('The id of the comment, as foresi_list_task_comments gives it.', format: 'uuid')]
        public string $commentId,
        #[Field('The comment, in Markdown.', maxLength: TaskComment::MAX_BODY_LENGTH)]
        public string $body,
    ) {
    }
}
