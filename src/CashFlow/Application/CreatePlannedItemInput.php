<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A new planned item over the API and MCP (ADR 0032): one-off on its day, or
 * recurring from that day when it repeats.
 */
#[Operation(
    name: 'cashflow.planned_items.create',
    method: 'POST',
    path: '/api/v1/cash-flow/planned-items',
    tool: 'foresi_create_planned_item',
    scope: 'cashflow:write',
    title: 'Create a planned item',
    description: 'Plans money a firm expects to receive or pay that no document carries yet, such as rent, software, insurance or tax advances, or a client not invoiced yet. It is kept outside the books and appears in foresi_cash_flow_forecast. Without repeat it falls once on its day; with repeat it recurs from that day every N days, weeks, months or years, until ends_on or after ends_after occurrences. With vat_mode included or excluded and vat_rate, the VAT counts on the days the firm is a VAT payer: the answer gives net, vat and vat_missing (true while the firm is a payer and no VAT is stated). Find contact ids with foresi_search_contacts.',
    status: 201,
    response: 'CashFlowPlannedItem',
)]
final readonly class CreatePlannedItemInput
{
    public function __construct(
        #[Field('in for money the firm receives, out for money it pays.', enum: ['in', 'out'])]
        public string $direction,
        #[Field('What it is, such as "Office rent".', maxLength: PlannedItem::MAX_NAME)]
        public string $name,
        #[Field('The forecast category.', enum: ['revenue', 'purchases', 'rent', 'software', 'payroll', 'taxes', 'insurance', 'vat', 'loans', 'owner_draws', 'other'])]
        public string $category,
        #[Field('The amount of one occurrence, positive, a decimal number such as "1500.00".')]
        public string $amount,
        #[Field('The day of a one-off item, or the first day of a recurring one, ISO 8601.', format: 'date')]
        public string $on,
        #[Field('The currency of the amount, ISO 4217.', enum: PlannedItemRules::CURRENCIES)]
        public string $currency = 'CZK',
        #[Field('How it repeats; left out or null, it falls once.', enum: PlannedItemRules::UNITS)]
        public ?string $repeat = null,
        #[Field('Repeats every this many days, weeks, months or years, 1 to 99.')]
        public int $every = 1,
        #[Field('A monthly or yearly item falls on the last day of its month.')]
        public bool $lastDayOfMonth = false,
        #[Field('The last day an occurrence may fall on, ISO 8601; null for no end date.', format: 'date')]
        public ?string $endsOn = null,
        #[Field('It ends after this many occurrences, 1 to 999; null for no count.')]
        public ?int $endsAfter = null,
        #[Field('The contact the money comes from or goes to.', format: 'uuid')]
        public ?string $counterpartyId = null,
        #[Field('Whether the forecast counts it; false keeps it for a "what if" without deleting it.')]
        public bool $included = true,
        #[Field('The counterparty bank account its payments come from or go to; a bank movement that meets the rule in the period of an occurrence fulfils it.', maxLength: PlannedItem::MAX_MATCH_ACCOUNT)]
        public ?string $matchAccount = null,
        #[Field('The variable symbol its payments carry, up to 10 digits; a bank movement that meets the rule in the period of an occurrence fulfils it.', maxLength: PlannedItem::MAX_VARIABLE_SYMBOL)]
        public ?string $matchVariableSymbol = null,
        #[Field('How far a payment may differ from the amount and still match, a decimal number such as "50.00".')]
        public ?string $matchTolerance = null,
        #[Field('How the amount stands to its VAT: none, included (the amount holds the VAT) or excluded (the VAT comes on top). The VAT counts only on the days the firm is a VAT payer.', enum: ['none', 'included', 'excluded'])]
        public string $vatMode = 'none',
        #[Field('The VAT rate in percent, 0 to 100 with at most two decimals, such as "21"; with vat_mode included or excluded only.')]
        public ?string $vatRate = null,
    ) {
    }
}
