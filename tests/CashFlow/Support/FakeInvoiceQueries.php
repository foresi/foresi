<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\Invoicing\Application\Query\InvoiceListItem;
use Foresi\Invoicing\Application\Query\InvoiceQueries;
use Foresi\Invoicing\Application\Query\InvoiceSlice;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;

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

    public function count(InvoiceSliceFilter $filter): int
    {
        return array_sum(array_map(\count(...), $this->slices));
    }
}
