<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\ChangePlannedItemInput;
use App\CashFlow\Application\PlannedItemWrites;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

final readonly class ChangePlannedItemHandler implements OperationHandler
{
    public function __construct(private PlannedItemWrites $writes, private PlannedItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ChangePlannedItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ChangePlannedItemInput);

        return $this->answer->of($this->writes->change($input), 'Changed the planned item %s.');
    }
}
