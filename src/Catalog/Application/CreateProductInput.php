<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A new product over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'catalog.products.create',
    method: 'POST',
    path: '/api/v1/catalog/products',
    tool: 'foresi_create_product',
    scope: 'catalog:write',
    title: 'Create a product',
    description: 'Adds a product to the catalog: something the firm sells, with its unit, an optional price per unit and how it is billed. A one_off product is billed once; a recurring one every interval of its recurrence_unit (interval 1 and month for every month), for a number of cycles or with no end when cycles is left out. A time_based product is billed by the hours worked: its unit is h, its unit_price is the hourly rate (required) and it has no recurrence. Products carry no VAT. Search first with foresi_search_products so the catalog holds no duplicate.',
    status: 201,
    response: 'CatalogProduct',
    staffOnly: true,
)]
final readonly class CreateProductInput
{
    public function __construct(
        #[Field('What the product is, such as "Roof audit".', maxLength: Product::MAX_NAME)]
        public string $name,
        #[Field('one_off for a product billed once, recurring for one billed on a period, time_based for one billed by the hours worked.', enum: ProductRules::BILLING_KINDS)]
        public string $billingKind = 'one_off',
        #[Field('The unit it is sold in, such as "hour" or "piece"; may be empty. For a time_based product the unit is h (empty means h).', maxLength: Product::MAX_UNIT)]
        public string $unit = '',
        #[Field('The price of one unit without VAT, a decimal number such as "1500.00"; left out or null when the price is agreed each time; required for a time_based product, where it is the hourly rate.')]
        public ?string $unitPrice = null,
        #[Field('The currency of the price, ISO 4217.', enum: ProductRules::CURRENCIES)]
        public string $currency = 'CZK',
        #[Field('A recurring product is billed every interval of this unit; null for a one_off or time_based product.', enum: ProductRules::UNITS)]
        public ?string $recurrenceUnit = null,
        #[Field('A recurring product is billed every this many units, 1 to 99; null for a one_off or time_based product.')]
        public ?int $interval = null,
        #[Field('How many times a recurring product is billed, 1 to 999; null for no end.')]
        public ?int $cycles = null,
        #[Field('What the product holds, for the people who sell it.', maxLength: Product::MAX_DESCRIPTION)]
        public string $description = '',
    ) {
    }
}
