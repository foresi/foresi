<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A product archived over the API and MCP (ADR 0032). Doing it twice changes nothing.
 */
#[Operation(
    name: 'catalog.products.archive',
    method: 'POST',
    path: '/api/v1/catalog/products/{productId}/archive',
    tool: 'foresi_archive_product',
    scope: 'catalog:write',
    title: 'Archive a product',
    description: 'Archives a product: it is no longer offered for new lines and is left out of foresi_search_products unless archived products are asked for. What already copied it keeps it. Restore it with foresi_restore_product.',
    response: 'CatalogProduct',
    staffOnly: true,
)]
final readonly class ArchiveProductInput
{
    public function __construct(
        #[Field('The id of the product.', format: 'uuid')]
        public string $productId,
    ) {
    }
}
