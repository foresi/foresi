<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A person's week of time, Monday to Sunday (Clockify, Costlocker timesheet).
 */
final readonly class TimeWeek
{
    /**
     * @param list<TimeDay> $days         seven, from Monday
     * @param int           $totalMinutes of the entries that are not running
     */
    public function __construct(
        public string $membershipId,
        public \DateTimeImmutable $monday,
        public array $days,
        public int $totalMinutes,
    ) {
    }
}
