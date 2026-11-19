<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

/**
 * One page of a view of the received documents, with what the tabs and the
 * filters offer: the count of each view that shows one, within the same firm,
 * month and text, the firms and the months with a document.
 */
final readonly class ReceivedDocumentPage
{
    /**
     * @param list<ReceivedDocumentRow> $rows
     * @param array<string, int>        $counts by the key of a view the filter counts
     * @param array<string, string>     $firms  name by id, of every firm of the organisation
     * @param list<string>              $months as YYYY-MM, newest first
     */
    public function __construct(
        public ReceivedDocumentFilter $filter,
        public array $rows,
        public int $total,
        public int $page,
        public int $pages,
        public array $counts,
        public array $firms,
        public array $months,
    ) {
    }
}
