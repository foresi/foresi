<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Domain\Product;

/**
 * The products of the catalog. A write is flushed by the unit of work around it.
 */
interface CatalogStore
{
    public function product(string $id): ?Product;

    /**
     * The products whose name or description holds $text, all of them for an
     * empty text, in name order.
     *
     * @return list<Product>
     */
    public function search(string $text, bool $includeArchived): array;

    public function add(Product $product): void;
}
