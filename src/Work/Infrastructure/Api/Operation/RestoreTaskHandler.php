<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\RestoreTaskInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * tasks.restore: the task out of the trash.
 */
final readonly class RestoreTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return RestoreTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreTaskInput);

        return $this->answer->task($this->writes->restoreTask($input), 'Restored the task %s.');
    }
}
