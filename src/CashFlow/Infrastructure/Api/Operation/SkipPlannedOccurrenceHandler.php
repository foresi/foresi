<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\PlannedItemWrites;
use App\CashFlow\Application\SkipPlannedOccurrenceInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

final readonly class SkipPlannedOccurrenceHandler implements OperationHandler
{
    public function __construct(private PlannedItemWrites $writes, private PlannedItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return SkipPlannedOccurrenceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SkipPlannedOccurrenceInput);

        return $this->answer->of($this->writes->skip($input), 'Skipped the occurrence of %s.');
    }
}
