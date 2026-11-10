<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoiceSums;
use App\Invoicing\Application\InvoicingAccess;
use App\Invoicing\Application\InvoicingNotAllowed;

/**
 * The lists of invoices for the screens, the API and MCP alike: a page with its tab
 * counts, what a list adds up to, and a slice for a client that pages by cursor. Only
 * the owners and members of the organisation read them.
 */
final readonly class InvoiceList
{
    public function __construct(
        private InvoicingAccess $access,
        private InvoiceStore $store,
        private InvoiceQueries $queries,
    ) {
    }

    /**
     * In the order of the filter; a page out of range gives the nearest one.
     *
     * @throws InvoicingNotAllowed for anyone but the owners and members of the organisation
     */
    public function page(InvoiceFilter $filter): InvoicePage
    {
        $this->access->denyUnlessStaff();

        return $this->store->page($filter);
    }

    /**
     * What the documents page() lists under the same filter add up to, over all pages.
     *
     * @throws InvoicingNotAllowed for anyone but the owners and members of the organisation
     */
    public function sums(InvoiceFilter $filter): InvoiceSums
    {
        $this->access->denyUnlessStaff();

        return $this->store->sums($filter);
    }

    /**
     * @param string|null $afterId the last id of the slice before, null for the first
     *
     * @throws InvoicingNotAllowed       for anyone but the owners and members of the organisation
     * @throws \InvalidArgumentException for a limit below one, or an id that is none
     */
    public function slice(InvoiceSliceFilter $filter, ?string $afterId, int $limit): InvoiceSlice
    {
        $this->access->denyUnlessStaff();

        return $this->queries->slice($filter, $afterId, $limit);
    }
}
