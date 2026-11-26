<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Field;

use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Application\Field\FieldRecord;

/**
 * Products take custom fields (ADR 0045) through ProductExtension.
 */
final readonly class ProductFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return ProductExtension::RECORD;
    }

    public function scope(): string
    {
        return 'catalog:read';
    }

    public function entity(): string
    {
        return Product::class;
    }

    public function extension(): string
    {
        return ProductExtension::class;
    }

    public function lists(): array
    {
        return [ProductLists::KEY => 'p'];
    }

    public function sorted(): array
    {
        return [];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof Product);

        return $row->id();
    }
}
