<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\SkipRecurringTaskOccurrenceInput;
use Foresi\Work\Application\ManageRecurringTasks;

/**
 * recurring-tasks.skip: the recurring task as it is now.
 */
final readonly class SkipRecurringTaskOccurrenceHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer)
    {
    }

    public static function input(): string
    {
        return SkipRecurringTaskOccurrenceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SkipRecurringTaskOccurrenceInput);

        return $this->answer->of($this->recurring->skip($input->recurringTaskId, $input->on), 'Skipped '.$input->on.' of the recurring task %s.');
    }
}
