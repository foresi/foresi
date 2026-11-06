<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What a document list shows over all its pages, for its download.
 */
interface ListedDocuments
{
    /**
     * Every document InvoiceStore::page() lists under the filter, over all pages, in its order.
     *
     * @return list<string> ids
     */
    public function listedIds(InvoiceFilter $filter): array;

    /**
     * The treatments and rates of the lines of what the list shows, over all pages,
     * as InvoiceStore::sums() splits them.
     *
     * @return list<InvoiceSumLine>
     */
    public function listedRates(InvoiceFilter $filter): array;

    /**
     * The documents of the active tenant among the ids, in the order of the ids. They
     * are not kept in the session, so a batch after a batch takes no more memory.
     *
     * @param list<string> $ids
     *
     * @return list<ListedDocument>
     */
    public function listedDocuments(array $ids): array;
}
