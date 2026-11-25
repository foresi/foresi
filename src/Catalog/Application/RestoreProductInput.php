<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An archived product restored over the API and MCP (ADR 0032). Doing it twice changes nothing.
 */
#[Operation(
    name: 'catalog.products.restore',
    method: 'POST',
    path: '/api/v1/catalog/products/{productId}/restore',
    tool: 'foresi_restore_product',
    scope: 'catalog:write',
    title: 'Restore a product',
    description: 'Restores an archived product, so it is offered again.',
    response: 'CatalogProduct',
    staffOnly: true,
)]
final readonly class RestoreProductInput
{
    public function __construct(
        #[Field('The id of the product.', format: 'uuid')]
        public string $productId,
    ) {
    }
}
