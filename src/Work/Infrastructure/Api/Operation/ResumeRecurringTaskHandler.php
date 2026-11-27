<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\ResumeRecurringTaskInput;
use Foresi\Work\Application\ManageRecurringTasks;

/**
 * recurring-tasks.resume: the recurring task as it is now.
 */
final readonly class ResumeRecurringTaskHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ResumeRecurringTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ResumeRecurringTaskInput);

        $skipped = $this->recurring->resume($input->recurringTaskId);

        return $this->answer->of($this->recurring->get($input->recurringTaskId), [] === $skipped ? 'Resumed the recurring task %s.' : 'Resumed the recurring task %s; the '.\count($skipped).' dates of the pause are skipped.');
    }
}
