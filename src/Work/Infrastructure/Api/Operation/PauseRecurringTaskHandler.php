<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\PauseRecurringTaskInput;
use Foresi\Work\Application\ManageRecurringTasks;

/**
 * recurring-tasks.pause: the recurring task as it is now.
 */
final readonly class PauseRecurringTaskHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer)
    {
    }

    public static function input(): string
    {
        return PauseRecurringTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof PauseRecurringTaskInput);

        return $this->answer->of($this->recurring->pause($input->recurringTaskId), 'Paused the recurring task %s.');
    }
}
