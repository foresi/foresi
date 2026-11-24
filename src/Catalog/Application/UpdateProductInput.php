<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

/**
 * A product changed over the API and MCP (ADR 0032): the fields given change,
 * the rest stay; an optional field sent as null is cleared.
 */
#[Operation(
    name: 'catalog.products.update',
    method: 'PATCH',
    path: '/api/v1/catalog/products/{productId}',
    tool: 'foresi_update_product',
    scope: 'catalog:write',
    title: 'Update a product',
    description: 'Changes the fields given of a product and keeps the rest; an optional field sent as null is cleared, so unit_price null leaves it without a price. Making a recurring product one_off or time_based clears its recurrence_unit, interval and cycles. What other records already copied from the product does not change.',
    response: 'CatalogProduct',
    staffOnly: true,
)]
final readonly class UpdateProductInput
{
    public function __construct(
        #[Field('The id of the product.', format: 'uuid')]
        public string $productId,
        #[Field('What the product is.', maxLength: Product::MAX_NAME)]
        public string|Omitted $name = new Omitted(),
        #[Field('one_off, recurring or time_based (billed by the hours worked).', enum: ProductRules::BILLING_KINDS)]
        public string|Omitted $billingKind = new Omitted(),
        #[Field('The unit it is sold in; may be empty. For a time_based product the unit is h.', maxLength: Product::MAX_UNIT)]
        public string|Omitted $unit = new Omitted(),
        #[Field('The price of one unit without VAT, a decimal number; null for no price, which a time_based product refuses (its price is the hourly rate).')]
        public string|Omitted|null $unitPrice = new Omitted(),
        #[Field('The currency of the price, ISO 4217.', enum: ProductRules::CURRENCIES)]
        public string|Omitted $currency = new Omitted(),
        #[Field('The unit of the period of a recurring product; null for a one_off or time_based product.', enum: ProductRules::UNITS)]
        public string|Omitted|null $recurrenceUnit = new Omitted(),
        #[Field('A recurring product is billed every this many units, 1 to 99.')]
        public int|Omitted|null $interval = new Omitted(),
        #[Field('How many times a recurring product is billed, 1 to 999; null for no end.')]
        public int|Omitted|null $cycles = new Omitted(),
        #[Field('What the product holds.', maxLength: Product::MAX_DESCRIPTION)]
        public string|Omitted $description = new Omitted(),
    ) {
    }
}
