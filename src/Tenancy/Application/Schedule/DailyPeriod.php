<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

/**
 * The day a daily job works for: the local date in the tenant's zone, which
 * begins at the start hour of the wall clock there. Before that hour it is still
 * yesterday. The hour is read off the local clock, not counted back from now, so
 * a day of the change to or from summer time begins at the same local hour.
 */
final class DailyPeriod
{
    /**
     * @param int $startHour the local hour the day begins at, 0 to 23
     *
     * @return string the day as "2026-11-01"
     */
    public static function of(\DateTimeImmutable $now, \DateTimeZone $zone, int $startHour): string
    {
        if ($startHour < 0 || $startHour > 23) {
            throw new \InvalidArgumentException(\sprintf('A day begins at an hour from 0 to 23, not %d.', $startHour));
        }

        $local = $now->setTimezone($zone);
        if ((int) $local->format('G') >= $startHour) {
            return $local->format('Y-m-d');
        }

        // The calendar date alone, in UTC, so going back a day crosses no clock change.
        return new \DateTimeImmutable($local->format('Y-m-d'), new \DateTimeZone('UTC'))->modify('-1 day')->format('Y-m-d');
    }
}
