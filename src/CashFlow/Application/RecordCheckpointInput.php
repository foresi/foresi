<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

/**
 * The balance of a firm's bank account at the end of a day.
 */
#[Operation(
    name: 'cashflow.checkpoints.record',
    method: 'POST',
    path: '/api/v1/cash-flow/checkpoints',
    tool: 'foresi_record_balance_checkpoint',
    scope: 'cashflow:write',
    title: 'Record a bank balance',
    description: 'Records the balance of a firm bank account at the end of a day, in the currency of the account, as the bank shows it. The forecast starts from the latest one of each account and warns when it is older than 7 days. Recording a day again corrects it. Find the account ids in balance.accounts of foresi_cash_flow_forecast, which lists every active bank account of the firm.',
    status: 201,
    response: 'CashFlowCheckpoint',
)]
final readonly class RecordCheckpointInput
{
    public function __construct(
        #[Field('The id of the firm bank account.', format: 'uuid')]
        public string $bankAccountId,
        #[Field('The day the balance is at the end of, today or before, ISO 8601.', format: 'date')]
        public string $on,
        #[Field('The balance in the currency of the account, a decimal number such as "125000.00"; negative for an overdraft.')]
        public string $amount,
    ) {
    }
}
