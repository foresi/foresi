<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Contract\Products;
use Foresi\Catalog\Contract\ProductSnapshot;
use Foresi\Catalog\Contract\UnknownProduct;
use Foresi\Catalog\Domain\Product;

/**
 * The products for other modules (Foresi\Catalog\Contract\Products), as
 * snapshots they copy onto lines of their own.
 */
final readonly class ProductCatalog implements Products
{
    public function __construct(private CatalogStore $store)
    {
    }

    public function get(string $id): ProductSnapshot
    {
        $product = $this->store->product($id) ?? throw UnknownProduct::withId($id);

        return self::snapshot($product);
    }

    public function search(string $text, bool $includeArchived = false): array
    {
        return array_map(self::snapshot(...), $this->store->search(mb_substr(trim($text), 0, ProductReads::MAX_TEXT), $includeArchived));
    }

    public static function snapshot(Product $product): ProductSnapshot
    {
        return new ProductSnapshot(
            $product->id(),
            $product->name(),
            $product->unit(),
            $product->unitPrice(),
            $product->billingKind(),
            $product->recurrenceUnit(),
            $product->interval(),
            $product->cycles(),
            $product->isArchived(),
        );
    }
}
