<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One day of a week of time, with what it adds up to.
 */
final readonly class TimeDay
{
    /**
     * @param list<TimeEntryRow> $entries      in the order they were logged
     * @param int                $totalMinutes of the entries that are not running
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $entries,
        public int $totalMinutes,
    ) {
    }
}
