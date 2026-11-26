<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Application\DealLineRules;
use Foresi\Sales\Domain\BillingEntryKind;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A planned invoice added to the billing plan of a won deal over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.billing_entries.create',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/billing-plan',
    tool: 'foresi_create_billing_entry',
    scope: 'sales:write',
    title: 'Plan an invoice of a won deal',
    description: 'Adds a planned invoice to the billing plan of a won deal; the cash-flow forecast counts it until an invoice or recurring invoice fulfils it. A one_off entry is an amount, given as amount or as percent of the deal\'s one-off value in the currency (the amount is stored, the percent is not); the one_off entries of a currency never exceed the deal\'s one-off value in it. A recurring entry follows one recurring line (line_id), takes its amount and period from it, and a line has one entry at most. expected_on is the day it is expected to be invoiced on; left out, it has no day and the forecast shows it under "No date", outside the balance. Winning a deal adds a starting plan already; read it with foresi_get_deal (billing_plan).',
    status: 201,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class CreateBillingEntryInput
{
    public function __construct(
        #[Field('The id of the won deal.', format: 'uuid')]
        public string $dealId,
        #[Field('one_off for an amount invoiced once, recurring for a recurring line of the deal.', enum: [BillingEntryKind::OneOff->value, BillingEntryKind::Recurring->value])]
        public string $kind = 'one_off',
        #[Field('A one_off entry: the amount without VAT, a decimal number such as "5000.00". Leave it out when percent is given.')]
        public ?string $amount = null,
        #[Field('A one_off entry: the currency of the amount, ISO 4217; the deal\'s one-off currency when it has one only.', enum: DealLineRules::CURRENCIES)]
        public ?string $currency = null,
        #[Field('A one_off entry: the share of the deal\'s one-off value in the currency, such as "50" for half; the amount it makes is stored, rounded to minor units.')]
        public ?string $percent = null,
        #[Field('A recurring entry: the id of the recurring line it follows.', format: 'uuid')]
        public ?string $lineId = null,
        #[Field('The day it is expected to be invoiced on, ISO 8601 (YYYY-MM-DD); for a recurring entry the day of the first invoice. Left out, it has no day.', format: 'date')]
        public ?string $expectedOn = null,
        #[Field('The text of the invoice issued from it; may be empty.', maxLength: BillingPlanEntry::MAX_TEXT)]
        public string $text = '',
    ) {
    }
}
