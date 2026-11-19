<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\TrashTaskInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * tasks.trash: the id of the task in the trash.
 */
final readonly class TrashTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes)
    {
    }

    public static function input(): string
    {
        return TrashTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashTaskInput);

        return new OperationOutput(['id' => $this->writes->trashTask($input), 'trashed' => true], 'Moved the task to the trash with its subtasks.');
    }
}
