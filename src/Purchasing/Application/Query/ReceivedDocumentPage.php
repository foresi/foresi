<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

/**
 * One page of a tab of the received documents, with what the tabs and the
 * filters offer: the count of each tab under the same filter, the firms, the
 * suppliers that have documents and the months that do.
 */
final readonly class ReceivedDocumentPage
{
    /**
     * @param list<ReceivedDocumentRow> $rows
     * @param array<string, int>        $tabCounts by tab, in the order of ReceivedDocumentFilter::TABS
     * @param array<string, string>     $firms     name by id, of every firm of the organisation
     * @param array<string, string>     $suppliers name by contact id, of the suppliers with a document
     * @param list<string>              $months    as YYYY-MM, newest first
     */
    public function __construct(
        public ReceivedDocumentFilter $filter,
        public array $rows,
        public int $total,
        public int $page,
        public int $pages,
        public array $tabCounts,
        public array $firms,
        public array $suppliers,
        public array $months,
    ) {
    }
}
