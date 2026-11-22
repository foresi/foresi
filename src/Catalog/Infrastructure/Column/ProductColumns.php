<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Column;

use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Catalog\Domain\Product;
use Foresi\Catalog\Infrastructure\Screen\ProductScreen;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the products; each cell renders one product: its name, unit,
 * price per unit and how it is billed.
 */
final readonly class ProductColumns implements DataSource
{
    public const string LIST = ProductLists::KEY;

    public function __construct(private ProductScreen $screen)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'catalog.products.column.'.$key;

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (Product $product): string => $product->name(), sort: 'p.name', defaultVisible: true, defaultPosition: 10, cell: static fn (Product $product): string => $product->name(), locked: true, truncate: true, filterable: true, filter: 'p.name'),
            new Attribute('unit', $label('unit'), AttributeType::Text, static fn (Product $product): string => $product->unit(), defaultVisible: true, defaultPosition: 20, cell: static fn (Product $product): string => $product->unit(), phone: false),
            new Attribute('unit_price', $label('unitPrice'), AttributeType::Money, static fn (Product $product): ?Money => $product->unitPrice(), defaultVisible: true, defaultPosition: 30, cell: $this->screen->price(...), aliases: ['unitPrice']),
            new Attribute('billing', $label('billing'), AttributeType::Text, $this->screen->billing(...), defaultVisible: true, defaultPosition: 40, cell: $this->screen->billing(...), phone: false, wide: true),
        ];
    }
}
