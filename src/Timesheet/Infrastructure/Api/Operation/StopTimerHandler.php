<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Timesheet\Application\Api\StopTimerInput;
use Foresi\Timesheet\Application\Api\TimeWrites;

/**
 * time_entries.stop_timer: the stopped entry.
 */
final readonly class StopTimerHandler implements OperationHandler
{
    public function __construct(private TimeWrites $writes, private TimesheetAnswers $answer)
    {
    }

    public static function input(): string
    {
        return StopTimerInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof StopTimerInput);

        return $this->answer->timeEntry($this->writes->stopTimer($input), 'Stopped the timer.');
    }
}
