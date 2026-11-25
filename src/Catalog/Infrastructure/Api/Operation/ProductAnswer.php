<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Domain\Product;
use Foresi\Catalog\Infrastructure\Api\CatalogApiController;
use Foresi\Catalog\Infrastructure\Api\CatalogJson;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The answer of a product write: the product, a summary and, for a new one, where it is read.
 */
final readonly class ProductAnswer
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function of(Product $product, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            CatalogJson::product($product),
            \sprintf($summary, $product->name()),
            $created ? $this->urls->generate(CatalogApiController::PRODUCT, ['productId' => $product->id()]) : null,
        );
    }
}
