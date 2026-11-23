<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Application\DealLineRules;
use Foresi\Sales\Domain\DealLine;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

/**
 * A line of a deal changed over the API and MCP (ADR 0032): the fields given
 * change, the rest stay; an optional field sent as null is cleared.
 */
#[Operation(
    name: 'sales.deal_lines.update',
    method: 'PATCH',
    path: '/api/v1/sales/deals/{dealId}/lines/{lineId}',
    tool: 'foresi_update_deal_line',
    scope: 'sales:write',
    title: 'Update a line of a deal',
    description: 'Changes the fields given of a line of a deal and keeps the rest; unit_price null leaves it without a price. Making a recurring line one_off clears its recurrence_unit, interval and cycles. The product it was copied from does not change.',
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
        #[Field('The unit it is sold in, such as "hour"; may be empty.', maxLength: DealLine::MAX_UNIT)]
        public string|Omitted $unit = new Omitted(),
        #[Field('How many units, a decimal number with up to three decimals, such as "1.5".')]
        public string|Omitted $quantity = new Omitted(),
        #[Field('The price of one unit without VAT, a decimal number such as "1500.00"; null for no price.')]
        public string|Omitted|null $unitPrice = new Omitted(),
        #[Field('The currency of the price, ISO 4217.', enum: DealLineRules::CURRENCIES)]
        public string|Omitted $currency = new Omitted(),
        #[Field('one_off for a line billed once, recurring for one billed on a period.', enum: DealLineRules::BILLING_KINDS)]
        public string|Omitted $billingKind = new Omitted(),
        #[Field('A recurring line is billed every interval of this unit; null for a one_off line.', enum: DealLineRules::UNITS)]
        public string|Omitted|null $recurrenceUnit = new Omitted(),
        #[Field('A recurring line is billed every this many units, 1 to 99.')]
        public int|Omitted|null $interval = new Omitted(),
        #[Field('How many times a recurring line is billed, 1 to 999; null for no end.')]
        public int|Omitted|null $cycles = new Omitted(),
    ) {
    }
}
