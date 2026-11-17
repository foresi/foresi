<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Support;

use App\Invoicing\Application\Query\InvoiceListItem;
use App\Invoicing\Application\Query\InvoiceQueries;
use App\Invoicing\Application\Query\InvoiceSlice;
use App\Invoicing\Application\Query\InvoiceSliceFilter;

/**
 * Answers the slices it was given, one per call, and keeps the filters it was asked with.
 */
final class FakeInvoiceQueries implements InvoiceQueries
{
    /** @var list<InvoiceSliceFilter> */
    public array $filters = [];

    /**
     * @param list<list<InvoiceListItem>> $slices
     */
    public function __construct(private readonly array $slices)
    {
    }

    public function slice(InvoiceSliceFilter $filter, ?string $afterId, int $limit): InvoiceSlice
    {
        $index = \count($this->filters);
        $this->filters[] = $filter;

        return new InvoiceSlice($this->slices[$index] ?? [], $index < \count($this->slices) - 1);
    }
}
