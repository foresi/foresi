<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Domain\Product;

/**
 * The products as the catalog's own screens, the API and MCP read them: only
 * the owners and members of the organisation.
 */
final readonly class ProductReads
{
    /** The longest text a search looks for. */
    public const int MAX_TEXT = 100;

    public function __construct(private CatalogAccess $access, private CatalogStore $store)
    {
    }

    /**
     * @throws CatalogRefused forbidden, not_found
     */
    public function one(string $id): Product
    {
        $this->access->denyUnlessStaff();

        return $this->store->product($id) ?? throw CatalogRefused::notFound();
    }

    /**
     * @return list<Product>
     *
     * @throws CatalogRefused forbidden
     */
    public function search(string $text, bool $includeArchived = false): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->search(self::searchText($text), $includeArchived);
    }

    /**
     * The text a search looks for: trimmed and cut to MAX_TEXT characters.
     */
    public static function searchText(string $text): string
    {
        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }
}
