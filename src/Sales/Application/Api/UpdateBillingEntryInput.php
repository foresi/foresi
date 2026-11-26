<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Application\DealLineRules;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A planned invoice of a won deal changed over the API and MCP (ADR 0032):
 * the fields given change, the rest stay; expected_on sent as null clears the day.
 */
#[Operation(
    name: 'sales.billing_entries.update',
    method: 'PATCH',
    path: '/api/v1/sales/deals/{dealId}/billing-plan/{entryId}',
    tool: 'foresi_update_billing_entry',
    scope: 'sales:write',
    title: 'Update a planned invoice of a won deal',
    description: 'Changes the fields given of a planned invoice and keeps the rest. Setting expected_on moves it from "No date" into its period of the cash-flow forecast; null takes the day away again. The amount, given as amount or percent, changes a one_off entry only; a recurring entry takes its amount from its line.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class UpdateBillingEntryInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the planned invoice.', format: 'uuid')]
        public string $entryId,
        #[Field('A one_off entry: the amount without VAT, a decimal number such as "5000.00".')]
        public string|Omitted $amount = new Omitted(),
        #[Field('A one_off entry: the currency of the amount, ISO 4217.', enum: DealLineRules::CURRENCIES)]
        public string|Omitted $currency = new Omitted(),
        #[Field('A one_off entry: the share of the deal\'s one-off value in the currency, such as "50"; the amount it makes is stored.')]
        public string|Omitted $percent = new Omitted(),
        #[Field('The day it is expected to be invoiced on, ISO 8601 (YYYY-MM-DD); null for no day.', format: 'date')]
        public string|Omitted|null $expectedOn = new Omitted(),
        #[Field('The text of the invoice issued from it; may be empty.', maxLength: BillingPlanEntry::MAX_TEXT)]
        public string|Omitted $text = new Omitted(),
    ) {
    }
}
