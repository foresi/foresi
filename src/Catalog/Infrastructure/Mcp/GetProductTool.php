<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Mcp;

use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\ProductReads;
use Foresi\Catalog\Infrastructure\Api\CatalogJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * One product, as GET /api/v1/catalog/products/{productId} writes it.
 */
final readonly class GetProductTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ProductReads $products)
    {
    }

    public function name(): string
    {
        return 'foresi_get_product';
    }

    public function title(): string
    {
        return 'Get a product';
    }

    public function scope(): string
    {
        return 'catalog:read';
    }

    public function description(): string
    {
        return 'Returns one product of the catalog with its unit, unit_price (null when the price is agreed each time), billing_kind, the period of a recurring one (every interval recurrence_unit, cycles times or with no end when cycles is null) and whether it is archived. Find ids with foresi_search_products.';
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
            'properties' => ['id' => ToolArguments::idSchema('The id of the product, as foresi_search_products returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $product = $this->products->one(ToolArguments::text($arguments, 'id') ?? '');
        } catch (CatalogRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(CatalogJson::product($product), \sprintf('The product %s.', $product->name()));
    }
}
