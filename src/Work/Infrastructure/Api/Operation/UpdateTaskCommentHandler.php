<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\UpdateTaskCommentInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * task_comments.update: the comment as it is now.
 */
final readonly class UpdateTaskCommentHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return UpdateTaskCommentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateTaskCommentInput);

        [$comment, $task] = $this->writes->editComment($input);

        return $this->answer->comment($comment, $task, 'Edited the comment.');
    }
}
