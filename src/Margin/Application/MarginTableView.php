<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The margin per client and month: the months of the range (YYYY-MM), a row
 * for each client with revenue or costs in it ordered by the revenue of the
 * range (highest first, then name), then the own-use row and the unassigned
 * row, which are always there. The totals add up the rows by month and
 * overall; the total costs are the sum of every cost share in the range,
 * unassigned included. The known gaps name what could not be counted, and
 * the unassigned documents count the documents behind the unassigned row.
 */
final readonly class MarginTableView
{
    /**
     * @param list<string>                 $months
     * @param list<MarginRowView>          $rows
     * @param array<string, MarginFigures> $monthTotals keyed by month
     * @param list<KnownGap>               $knownGaps
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $months,
        public array $rows,
        public array $monthTotals,
        public MarginFigures $total,
        public array $knownGaps,
        public int $unassignedDocuments,
    ) {
    }
}
