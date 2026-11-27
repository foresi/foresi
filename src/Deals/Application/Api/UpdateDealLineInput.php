<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Deals\Application\DealLineRules;
use Foresi\Deals\Domain\DealLine;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A line of a deal changed over the API and MCP (ADR 0032): the fields given
 * change, the rest stay; an optional field sent as null is cleared.
 */
#[Operation(
    name: 'deals.lines.update',
    method: 'PATCH',
    path: '/api/v1/deals/{dealId}/lines/{lineId}',
    tool: 'foresi_update_deal_line',
    scope: 'deals:write',
    title: 'Update a line of a deal',
    description: 'Changes the fields given of a line of a deal and keeps the rest; unit_price null leaves it without a price. A time_based line has the unit h, its unit_price is the hourly rate (needed) and it has no recurrence. Making a recurring line one_off or time_based clears its recurrence_unit, interval and cycles. project_id links a time_based line to the project whose billable time it bills at its rate, null unlinks it; time already invoiced keeps the rate it took. The product it was copied from does not change.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class UpdateDealLineInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the line.', format: 'uuid')]
        public string $lineId,
        #[Field('What the line sells.', maxLength: DealLine::MAX_NAME)]
        public string|Omitted $name = new Omitted(),
        #[Field('The unit it is sold in, such as "piece"; may be empty. A time_based line has the unit h.', maxLength: DealLine::MAX_UNIT)]
        public string|Omitted $unit = new Omitted(),
        #[Field('How many units, a decimal number with up to three decimals, such as "1.5". For a time_based line it is the hours expected, and null means not estimated; the other kinds need one.')]
        public string|Omitted|null $quantity = new Omitted(),
        #[Field('The price of one unit without VAT, a decimal number such as "1500.00"; null for no price.')]
        public string|Omitted|null $unitPrice = new Omitted(),
        #[Field('The currency of the price, ISO 4217.', enum: DealLineRules::CURRENCIES)]
        public string|Omitted $currency = new Omitted(),
        #[Field('one_off for a line billed once, recurring for one billed on a period, time_based for one billed by the hour at the unit price.', enum: DealLineRules::BILLING_KINDS)]
        public string|Omitted $billingKind = new Omitted(),
        #[Field('A recurring line is billed every interval of this unit; null for a one_off or time_based line.', enum: DealLineRules::UNITS)]
        public string|Omitted|null $recurrenceUnit = new Omitted(),
        #[Field('A recurring line is billed every this many units, 1 to 99.')]
        public int|Omitted|null $interval = new Omitted(),
        #[Field('How many times a recurring line is billed, 1 to 999; null for no end.')]
        public int|Omitted|null $cycles = new Omitted(),
        #[Field('The project (from foresi_search_projects) whose billable time a time_based line bills at its rate; null unlinks it. Only a time_based line has one, of the deal\'s client or of no client, and a project serves one line; make the line another kind only after unlinking it.', format: 'uuid')]
        public string|Omitted|null $projectId = new Omitted(),
    ) {
    }
}
