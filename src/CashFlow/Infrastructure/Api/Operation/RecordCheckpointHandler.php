<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RecordCheckpointHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes, private CashFlowAccess $access)
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

        return new OperationOutput(CashFlowJson::checkpoint($checkpoint, $this->access->firm()), \sprintf('Recorded the balance %s %s of %s at the end of %s.', PlannedItemWrites::decimal($checkpoint->amount()), $checkpoint->amount()->currency()->value, $checkpoint->account()->label(), $checkpoint->on()->format('Y-m-d')));
    }
}
