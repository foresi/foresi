<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\AddTaskCommentInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * tasks.comment: the new comment.
 */
final readonly class CommentTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return AddTaskCommentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddTaskCommentInput);

        [$comment, $task] = $this->writes->comment($input);

        return $this->answer->comment($comment, $task, 'Added the comment.', true);
    }
}
