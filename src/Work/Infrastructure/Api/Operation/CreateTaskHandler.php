<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateTaskInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * tasks.create: the new task.
 */
final readonly class CreateTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return CreateTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateTaskInput);

        return $this->answer->task($this->writes->createTask($input), 'Created the task %s.', true);
    }
}
