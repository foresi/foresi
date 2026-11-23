<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Application\DealLineRules;
use Foresi\Sales\Domain\DealLine;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A line added to a deal over the API and MCP (ADR 0032): from a product of
 * the catalog, or typed in.
 */
#[Operation(
    name: 'sales.deal_lines.add',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/lines',
    tool: 'foresi_add_deal_line',
    scope: 'sales:write',
    title: 'Add a line to a deal',
    description: 'Adds what a deal sells. With product_id (from foresi_search_products) the line copies the product\'s name, unit, price and billing as they are now, and a later change of the product leaves it as it is; give only the quantity then. Without a product, type the name, unit, price and billing in: a one_off line is billed once, a recurring one every interval of its recurrence_unit. A line without a price is left out of the deal\'s value.',
    status: 201,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class AddDealLineInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The product of the catalog to copy; left out for a line typed in.', format: 'uuid')]
        public ?string $productId = null,
        #[Field('What the line sells.', maxLength: DealLine::MAX_NAME)]
        public ?string $name = null,
        #[Field('The unit it is sold in, such as "hour"; may be empty.', maxLength: DealLine::MAX_UNIT)]
        public ?string $unit = null,
        #[Field('How many units, a decimal number with up to three decimals, such as "1.5".')]
        public string $quantity = '1',
        #[Field('The price of one unit without VAT, a decimal number such as "1500.00"; null for no price.')]
        public ?string $unitPrice = null,
        #[Field('The currency of the price, ISO 4217.', enum: DealLineRules::CURRENCIES)]
        public ?string $currency = null,
        #[Field('one_off for a line billed once, recurring for one billed on a period.', enum: DealLineRules::BILLING_KINDS)]
        public ?string $billingKind = null,
        #[Field('A recurring line is billed every interval of this unit; null for a one_off line.', enum: DealLineRules::UNITS)]
        public ?string $recurrenceUnit = null,
        #[Field('A recurring line is billed every this many units, 1 to 99.')]
        public ?int $interval = null,
        #[Field('How many times a recurring line is billed, 1 to 999; null for no end.')]
        public ?int $cycles = null,
    ) {
    }
}
