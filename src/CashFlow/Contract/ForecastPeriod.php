<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract;

/**
 * One column of the forecast: an ISO week (Monday to Sunday) or a calendar
 * month. The first period starts on the first day of the forecast and the
 * first month on the day after the last week, so either may be shorter.
 */
final readonly class ForecastPeriod
{
    /**
     * @param \DateTimeImmutable $start the first day
     * @param \DateTimeImmutable $end   the last day, inclusive
     */
    public function __construct(
        public PeriodKind $kind,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    /**
     * "2026-W47" for a week, "2027-01" for a month.
     */
    public function key(): string
    {
        return PeriodKind::Week === $this->kind ? $this->start->format('o-\WW') : $this->start->format('Y-m');
    }

    public function contains(\DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');

        return $date >= $this->start->format('Y-m-d') && $date <= $this->end->format('Y-m-d');
    }
}
