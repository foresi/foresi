<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Application\View;

use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Shared\Application\View\DefaultView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The product list ships All, One-off, Recurring and Archived in that order,
 * none of them counting its products, and names each billing kind.
 */
final class ProductListsTest extends KernelTestCase
{
    public function testShippedViews(): void
    {
        $lists = self::getContainer()->get(ProductLists::class);
        $list = $lists->list();

        self::assertSame($list, $lists->list());
        self::assertSame(
            [['all', 'catalog.products.tab.all', false, 0], ['one_off', 'catalog.products.tab.one_off', false, 1], ['recurring', 'catalog.products.tab.recurring', false, 2], ['archived', 'catalog.products.tab.archived', false, 3]],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->showCount, $view->position], $list->defaults),
        );
        self::assertSame(['one_off' => 'catalog.billing_kind.one_off', 'recurring' => 'catalog.billing_kind.recurring'], $list->field('billing_kind')?->optionLabels);
    }
}
