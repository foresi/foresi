<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * The balance the firm of the instance wants to keep.
 */
#[Operation(
    name: 'cashflow.minimum_balance.set',
    method: 'POST',
    path: '/api/v1/cash-flow/minimum-balance',
    tool: 'foresi_set_minimum_balance',
    scope: 'cashflow:write',
    title: 'Set the minimum balance',
    description: 'Sets the balance in CZK the firm wants to keep; foresi_cash_flow_forecast then answers the first day the projected balance falls below it as shortfall_on. Null removes it.',
    response: 'CashFlowMinimumBalance',
)]
final readonly class SetMinimumBalanceInput
{
    public function __construct(
        #[Field('The minimum balance in CZK, a decimal number such as "50000.00"; null removes it.')]
        public ?string $amount,
    ) {
    }
}
