<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

/**
 * The balance a firm wants to keep.
 */
#[Operation(
    name: 'cashflow.minimum_balance.set',
    method: 'POST',
    path: '/api/v1/cash-flow/firms/{firmId}/minimum-balance',
    tool: 'foresi_set_minimum_balance',
    scope: 'cashflow:write',
    title: 'Set the minimum balance of a firm',
    description: 'Sets the balance in CZK the firm wants to keep; foresi_cash_flow_forecast then answers the first day the projected balance falls below it as shortfall_on. Null removes it.',
    response: 'CashFlowMinimumBalance',
)]
final readonly class SetMinimumBalanceInput
{
    public function __construct(
        #[Field('The id of the firm.', format: 'uuid')]
        public string $firmId,
        #[Field('The minimum balance in CZK, a decimal number such as "50000.00"; null removes it.')]
        public ?string $amount,
    ) {
    }
}
