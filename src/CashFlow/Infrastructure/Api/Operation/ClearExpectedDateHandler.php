<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\ClearExpectedDateInput;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

final readonly class ClearExpectedDateHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes)
    {
    }

    public static function input(): string
    {
        return ClearExpectedDateInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ClearExpectedDateInput);
        [$type, $id] = $this->writes->clearExpected($input);

        return new OperationOutput(CashFlowJson::expectedDate($type, $id, null), \sprintf('The %s is expected on its due date again.', $type));
    }
}
