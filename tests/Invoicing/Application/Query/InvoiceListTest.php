<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceSlice;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;

final class InvoiceListTest extends InvoicingReadTestCase
{
    public function testTheTabOfTheFilterFollowsTheTrashThenTheState(): void
    {
        self::assertSame(InvoiceFilter::ALL, new InvoiceSliceFilter()->tab());
        self::assertSame(InvoiceFilter::PAID, new InvoiceSliceFilter(state: InvoiceFilter::PAID)->tab());
        self::assertSame(InvoiceFilter::TRASH, new InvoiceSliceFilter(state: InvoiceFilter::PAID, trashed: true)->tab());
    }

    public function testASliceOfNothingIsEmpty(): void
    {
        $slice = $this->slice(new InvoiceSliceFilter());

        self::assertSame([], $slice->items);
        self::assertFalse($slice->hasMore);
        self::assertNull($slice->nextAfterId());
        self::assertNull(new InvoiceSlice([], true)->nextAfterId());
    }

    private function list(): InvoiceList
    {
        return self::getContainer()->get(InvoiceList::class);
    }

    private function slice(InvoiceSliceFilter $filter, ?string $after = null, int $limit = 25): InvoiceSlice
    {
        $this->em->clear();

        return $this->list()->slice($filter, $after, $limit);
    }
}
