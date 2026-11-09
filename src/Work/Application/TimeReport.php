<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A time report: its totals by the grouping chosen, the sum of them all and
 * one page of its entries, newest first. A running timer is not in it.
 */
final readonly class TimeReport
{
    /**
     * @param list<TimeTotal>    $totals  the most time first
     * @param list<TimeEntryRow> $entries the page asked for
     */
    public function __construct(
        public array $totals,
        public int $totalMinutes,
        public int $billableMinutes,
        public int $entryCount,
        public array $entries,
        public int $page,
        public int $pages,
    ) {
    }
}
