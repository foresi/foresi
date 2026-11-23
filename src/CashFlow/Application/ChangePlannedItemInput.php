<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

/**
 * A partial change of a planned item: a field left out keeps its value, an
 * optional field sent as null is cleared. The firm and the direction stay.
 */
#[Operation(
    name: 'cashflow.planned_items.change',
    method: 'PATCH',
    path: '/api/v1/cash-flow/planned-items/{plannedItemId}',
    tool: 'foresi_change_planned_item',
    scope: 'cashflow:write',
    title: 'Change a planned item',
    description: 'Changes the fields given of a planned item and keeps the rest; an optional field sent as null is cleared, so repeat null makes it one-off. The firm and the direction do not change. Set included false to leave it out of the forecast without deleting it. Skipped occurrences stay skipped.',
    response: 'CashFlowPlannedItem',
)]
final readonly class ChangePlannedItemInput
{
    public function __construct(
        #[Field('The id of the planned item.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('What it is, such as "Office rent".', maxLength: PlannedItem::MAX_NAME)]
        public string|Omitted $name = new Omitted(),
        #[Field('The forecast category.', enum: ['revenue', 'purchases', 'rent', 'software', 'payroll', 'taxes', 'insurance', 'vat', 'loans', 'owner_draws', 'other'])]
        public string|Omitted $category = new Omitted(),
        #[Field('The amount of one occurrence, positive, a decimal number such as "1500.00".')]
        public string|Omitted $amount = new Omitted(),
        #[Field('The currency of the amount, ISO 4217.', enum: PlannedItemRules::CURRENCIES)]
        public string|Omitted $currency = new Omitted(),
        #[Field('The day of a one-off item, or the first day of a recurring one, ISO 8601.', format: 'date')]
        public string|Omitted $on = new Omitted(),
        #[Field('How it repeats; null makes it one-off.', enum: PlannedItemRules::UNITS)]
        public string|Omitted|null $repeat = new Omitted(),
        #[Field('Repeats every this many days, weeks, months or years, 1 to 99.')]
        public int|Omitted $every = new Omitted(),
        #[Field('A monthly or yearly item falls on the last day of its month.')]
        public bool|Omitted $lastDayOfMonth = new Omitted(),
        #[Field('The last day an occurrence may fall on, ISO 8601; null for no end date.', format: 'date')]
        public string|Omitted|null $endsOn = new Omitted(),
        #[Field('It ends after this many occurrences, 1 to 999; null for no count.')]
        public int|Omitted|null $endsAfter = new Omitted(),
        #[Field('The contact the money comes from or goes to; null for none.', format: 'uuid')]
        public string|Omitted|null $counterpartyId = new Omitted(),
        #[Field('Whether the forecast counts it.')]
        public bool|Omitted $included = new Omitted(),
        #[Field('The counterparty bank account its payments come from or go to; null for none.', maxLength: PlannedItem::MAX_MATCH_ACCOUNT)]
        public string|Omitted|null $matchAccount = new Omitted(),
        #[Field('The variable symbol its payments carry, up to 10 digits; null for none.', maxLength: PlannedItem::MAX_VARIABLE_SYMBOL)]
        public string|Omitted|null $matchVariableSymbol = new Omitted(),
        #[Field('How far a payment may differ from the amount and still match, a decimal number; null for none.')]
        public string|Omitted|null $matchTolerance = new Omitted(),
        #[Field('How the amount stands to its VAT: none, included (the amount holds the VAT), excluded (the VAT comes on top) or reverse_charge (money out only: the firm self-assesses the VAT, as for a domestic reverse charge or a service from the EU or from outside it; the amount moves as stated and the VAT counts as both output and input); none clears the rate.', enum: ['none', 'included', 'excluded', 'reverse_charge'])]
        public string|Omitted $vatMode = new Omitted(),
        #[Field('The VAT rate in percent, 0 to 100 with at most two decimals, such as "21"; null for none.')]
        public string|Omitted|null $vatRate = new Omitted(),
    ) {
    }
}
