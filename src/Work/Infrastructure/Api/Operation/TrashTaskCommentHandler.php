<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\TrashTaskCommentInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * task_comments.trash: the id of the deleted comment and of its task.
 */
final readonly class TrashTaskCommentHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes)
    {
    }

    public static function input(): string
    {
        return TrashTaskCommentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashTaskCommentInput);

        [$comment, $task] = $this->writes->trashComment($input);

        return new OperationOutput(['id' => $comment, 'task_id' => $task, 'deleted' => true], 'Deleted the comment.');
    }
}
