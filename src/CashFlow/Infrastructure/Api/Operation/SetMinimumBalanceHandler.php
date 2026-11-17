<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\PlannedItemWrites;
use App\CashFlow\Application\SetMinimumBalanceInput;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

final readonly class SetMinimumBalanceHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetMinimumBalanceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetMinimumBalanceInput);
        $amount = $this->writes->minimum($input)?->amount();

        return new OperationOutput(
            CashFlowJson::minimumBalance($input->firmId, $amount),
            $amount instanceof \App\Shared\Domain\Money ? \sprintf('The minimum balance of the firm is %s CZK.', PlannedItemWrites::decimal($amount)) : 'The firm has no minimum balance now.',
        );
    }
}
