<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Mcp;

use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\ProductReads;
use Foresi\Catalog\Infrastructure\Api\CatalogJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The products of the catalog found by text, as GET /api/v1/catalog/products writes them.
 */
final readonly class SearchProductsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ProductReads $products)
    {
    }

    public function name(): string
    {
        return 'foresi_search_products';
    }

    public function title(): string
    {
        return 'Search products';
    }

    public function scope(): string
    {
        return 'catalog:read';
    }

    public function description(): string
    {
        return 'Returns the products of the catalog whose name or description holds the text, all of them without one, in name order: {items} with the id, name, description, unit, unit_price, billing_kind (one_off, recurring or time_based, billed by the hour at unit_price), recurrence_unit, interval, cycles and whether it is archived. '
            .'Archived products are left out unless archived is true. Use an id with foresi_get_product, foresi_update_product, foresi_archive_product or foresi_restore_product.';
    }

    /**
     * The firm's catalog, never a guest's (ADR 0041).
     */
    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => ProductReads::MAX_TEXT, 'description' => 'The text the name or the description holds; left out for every product.'],
                'archived' => ['type' => 'boolean', 'description' => 'true includes the archived products.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $products = $this->products->search(ToolSchema::text($arguments, 'query') ?? '', ToolSchema::flag($arguments, 'archived'));
        } catch (CatalogRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($products);

        return new ToolOutput(
            ['items' => array_map(CatalogJson::product(...), $products)],
            1 === $count ? '1 product.' : \sprintf('%d products, in name order.', $count),
        );
    }
}
