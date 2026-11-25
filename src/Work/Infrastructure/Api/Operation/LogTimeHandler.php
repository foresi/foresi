<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\LogTimeInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * time_entries.log: the new entry.
 */
final readonly class LogTimeHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return LogTimeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LogTimeInput);

        return $this->answer->timeEntry($this->writes->logTime($input), 'Logged the time.');
    }
}
