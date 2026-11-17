<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\PlannedItemWrites;
use App\CashFlow\Application\RecordCheckpointInput;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RecordCheckpointHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes)
    {
    }

    public static function input(): string
    {
        return RecordCheckpointInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RecordCheckpointInput);
        $checkpoint = $this->writes->checkpoint($input);

        return new OperationOutput(CashFlowJson::checkpoint($checkpoint), \sprintf('Recorded the balance %s %s of %s at the end of %s.', PlannedItemWrites::decimal($checkpoint->amount()), $checkpoint->amount()->currency()->value, $checkpoint->account()->label(), $checkpoint->on()->format('Y-m-d')));
    }
}
