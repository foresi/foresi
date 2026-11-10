<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The time of a task: its entries, what they add up to and the estimate
 * (Teamwork's estimated against logged).
 */
final readonly class TaskTime
{
    /**
     * @param list<TimeEntryRow> $entries      newest day first
     * @param int                $totalMinutes of the entries that are not running
     */
    public function __construct(
        public string $taskId,
        public array $entries,
        public int $totalMinutes,
        public ?int $estimateMinutes,
    ) {
    }
}
