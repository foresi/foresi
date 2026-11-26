<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\LogTimeInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Infrastructure\Api\Operation\WorkAnswers;

/**
 * inbox.actions.log_time: the same entry time_entries.log makes.
 */
final readonly class LogTimeFromEmailHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return LogTimeFromEmailInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LogTimeFromEmailInput);

        return $this->answer->timeEntry($this->writes->logTime(new LogTimeInput(
            projectId: $input->projectId,
            workedOn: $input->workedOn,
            taskId: $input->taskId,
            minutes: $input->minutes,
            startedAt: $input->startedAt,
            endedAt: $input->endedAt,
            note: $input->note,
            billable: $input->billable,
        )), 'Logged the time.');
    }
}
