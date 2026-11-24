<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Infrastructure;

use Foresi\Catalog\Application\ProductQueries;
use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Catalog\Support\CatalogTestCase;

/**
 * The text search of the product list: the name or the description, whatever the
 * case, with the wildcards of LIKE taken as the characters they are.
 */
final class ProductListSearchTest extends CatalogTestCase
{
    public function testTheListIsFoundByTheNameOrTheDescriptionWhateverTheCase(): void
    {
        $this->product('Roof audit', description: 'A visit to the site');
        $this->product('Gutter repair', description: '100% of the gutters');
        $queries = self::getContainer()->get(ProductQueries::class);

        $found = static fn (string $text): array => array_map(static fn (Product $product): string => $product->name(), $queries->index(new ViewFilter(), false, $text));
        self::assertSame(['Gutter repair', 'Roof audit'], $found(''));
        self::assertSame(['Roof audit'], $found('ROOF'));
        self::assertSame(['Roof audit'], $found('visit to'), 'by the description');
        self::assertSame(['Gutter repair'], $found('100%'), 'a percent sign is a percent sign');
        self::assertSame([], $found('%x'));
        self::assertSame([], $found('_oof'));
        self::assertSame(['Roof audit', 'Gutter repair'], array_map(static fn (Product $product): string => $product->name(), $queries->index(new ViewFilter(), true, 'r')), 'a text keeps the order asked for');
    }

    public function testTheHourlyViewHoldsTheTimeBasedProducts(): void
    {
        $this->product('Roof audit');
        $this->product('Support', billingKind: 'time_based', unit: 'h', unitPrice: '1200.00');
        $list = self::getContainer()->get(ProductLists::class)->list();
        $hourly = array_values(array_filter($list->defaults, static fn (DefaultView $view): bool => 'hourly' === $view->slug))[0];
        $queries = self::getContainer()->get(ProductQueries::class);

        self::assertSame('catalog.products.tab.hourly', $hourly->name);
        self::assertSame(['Support'], array_map(static fn (Product $product): string => $product->name(), $queries->index($hourly->filter, false, '')));
    }
}
