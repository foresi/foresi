<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\StartTimerInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * time_entries.start_timer: the running entry.
 */
final readonly class StartTimerHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
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
