<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\SetMinimumBalanceInput;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class SetMinimumBalanceHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes, private CashFlowAccess $access)
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
            CashFlowJson::minimumBalance($this->access->firm()->id(), $amount),
            $amount instanceof \Foresi\Shared\Domain\Money ? \sprintf('The minimum balance of the firm is %s CZK.', PlannedItemWrites::decimal($amount)) : 'The firm has no minimum balance now.',
        );
    }
}
