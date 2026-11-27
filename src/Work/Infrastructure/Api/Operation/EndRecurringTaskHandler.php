<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\EndRecurringTaskInput;
use Foresi\Work\Application\ManageRecurringTasks;

/**
 * recurring-tasks.end: the recurring task as it is now.
 */
final readonly class EndRecurringTaskHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer)
    {
    }

    public static function input(): string
    {
        return EndRecurringTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof EndRecurringTaskInput);

        return $this->answer->of($this->recurring->end($input->recurringTaskId), 'Ended the recurring task %s; it creates no more tasks.');
    }
}
