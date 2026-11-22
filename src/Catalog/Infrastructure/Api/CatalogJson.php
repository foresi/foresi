<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api;

use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * A product as REST and MCP write it (schema CatalogProduct).
 */
final class CatalogJson
{
    /**
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        $price = $product->unitPrice();

        return [
            'id' => $product->id(),
            'name' => $product->name(),
            'description' => $product->description(),
            'unit' => $product->unit(),
            'unit_price' => $price instanceof Money ? JsonValues::money($price) : null,
            'billing_kind' => $product->billingKind()->value,
            'recurrence_unit' => $product->recurrenceUnit()?->value,
            'interval' => $product->interval(),
            'cycles' => $product->cycles(),
            'archived' => $product->isArchived(),
            'archived_at' => JsonValues::timestamp($product->archivedAt()),
            'created_at' => JsonValues::timestamp($product->createdAt()),
        ];
    }
}
