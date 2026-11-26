<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Timesheet\Application\Api\StartTimerInput;
use Foresi\Timesheet\Application\Api\TimeWrites;

/**
 * time_entries.start_timer: the running entry.
 */
final readonly class StartTimerHandler implements OperationHandler
{
    public function __construct(private TimeWrites $writes, private TimesheetAnswers $answer)
    {
    }

    public static function input(): string
    {
        return StartTimerInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof StartTimerInput);

        return $this->answer->timeEntry($this->writes->startTimer($input), 'Started the timer.');
    }
}
