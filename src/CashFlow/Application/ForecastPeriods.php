<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Contract\ForecastPeriod;
use App\CashFlow\Contract\PeriodKind;

/**
 * The columns of a forecast: ISO weeks from its first day up to the week that
 * holds the last day for weeks, then calendar months up to the end of the
 * horizon. The first week starts on the first day, and the first month on
 * the day after the last week, so either may be shorter than a whole one.
 */
final class ForecastPeriods
{
    /**
     * @param \DateTimeImmutable $from       the first day, at midnight
     * @param int                $months     the horizon: it ends with the last day of the month $months - 1 after the month of $from
     * @param \DateTimeImmutable $weeksUntil weeks are used while a week starts on or before this day
     *
     * @return list<ForecastPeriod>
     */
    public static function of(\DateTimeImmutable $from, int $months, \DateTimeImmutable $weeksUntil): array
    {
        $end = self::horizonEnd($from, $months);
        $periods = [];
        $start = $from;
        while (self::day($start) <= self::day($weeksUntil) && self::day($start) <= self::day($end)) {
            $last = min($start->modify('sunday this week'), $end);
            $periods[] = new ForecastPeriod(PeriodKind::Week, $start, $last);
            $start = $last->modify('+1 day');
        }
        while (self::day($start) <= self::day($end)) {
            $last = $start->modify('last day of this month');
            $periods[] = new ForecastPeriod(PeriodKind::Month, $start, $last);
            $start = $last->modify('+1 day');
        }

        return $periods;
    }

    /**
     * The last day of the month after the month of $from: the default end of the weeks.
     */
    public static function endOfNextMonth(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->modify('first day of next month')->modify('last day of this month');
    }

    public static function horizonEnd(\DateTimeImmutable $from, int $months): \DateTimeImmutable
    {
        return $from->modify('first day of this month')->modify(\sprintf('+%d months', $months - 1))->modify('last day of this month');
    }

    private static function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
