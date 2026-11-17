<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Application;

use App\CashFlow\Application\ForecastPeriods;
use App\CashFlow\Contract\ForecastPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The columns of the forecast (C8): ISO weeks until the end of next month,
 * then calendar months, the first month from the day after the last week.
 */
final class ForecastPeriodsTest extends TestCase
{
    public function testWeeksUntilTheEndOfNextMonthThenMonthsForAYear(): void
    {
        $from = $this->day('2026-11-17');

        $periods = ForecastPeriods::of($from, 12, ForecastPeriods::endOfNextMonth($from));

        self::assertSame([
            'week 2026-W47 2026-11-17 2026-11-22',
            'week 2026-W48 2026-11-23 2026-11-29',
            'week 2026-W49 2026-11-30 2026-12-06',
            'week 2026-W50 2026-12-07 2026-12-13',
            'week 2026-W51 2026-12-14 2026-12-20',
            'week 2026-W52 2026-12-21 2026-12-27',
            'week 2026-W53 2026-12-28 2027-01-03',
            'month 2027-01 2027-01-04 2027-01-31',
            'month 2027-02 2027-02-01 2027-02-28',
            'month 2027-03 2027-03-01 2027-03-31',
            'month 2027-04 2027-04-01 2027-04-30',
            'month 2027-05 2027-05-01 2027-05-31',
            'month 2027-06 2027-06-01 2027-06-30',
            'month 2027-07 2027-07-01 2027-07-31',
            'month 2027-08 2027-08-01 2027-08-31',
            'month 2027-09 2027-09-01 2027-09-30',
            'month 2027-10 2027-10-01 2027-10-31',
        ], $this->describe($periods));
        self::assertSame('2027-10-31', ForecastPeriods::horizonEnd($from, 12)->format('Y-m-d'));
        self::assertSame('2026-12-31', ForecastPeriods::endOfNextMonth($from)->format('Y-m-d'));
    }

    public function testAWeekThatStartsOnTheLastDayForWeeksIsStillAWeek(): void
    {
        $periods = ForecastPeriods::of($this->day('2026-11-29'), 2, $this->day('2026-11-30'));

        // Sunday is a week of one day, Monday 30 starts the last week, then December from the 7th.
        self::assertSame([
            'week 2026-W48 2026-11-29 2026-11-29',
            'week 2026-W49 2026-11-30 2026-12-06',
            'month 2026-12 2026-12-07 2026-12-31',
        ], $this->describe($periods));
    }

    public function testWithoutWeeksTheFirstMonthStartsOnTheFirstDay(): void
    {
        $periods = ForecastPeriods::of($this->day('2026-11-17'), 2, $this->day('2026-11-16'));

        self::assertSame(['month 2026-11 2026-11-17 2026-11-30', 'month 2026-12 2026-12-01 2026-12-31'], $this->describe($periods));
    }

    public function testTheHorizonCutsTheLastWeek(): void
    {
        $periods = ForecastPeriods::of($this->day('2026-11-17'), 1, $this->day('2026-12-31'));

        self::assertSame([
            'week 2026-W47 2026-11-17 2026-11-22',
            'week 2026-W48 2026-11-23 2026-11-29',
            'week 2026-W49 2026-11-30 2026-11-30',
        ], $this->describe($periods));
    }

    public function testAPeriodContainsItsFirstAndLastDayOnly(): void
    {
        $period = ForecastPeriods::of($this->day('2026-11-17'), 1, $this->day('2026-11-17'))[0];

        self::assertFalse($period->contains($this->day('2026-11-16')));
        self::assertTrue($period->contains($this->day('2026-11-17')));
        self::assertTrue($period->contains(new \DateTimeImmutable('2026-11-22 23:59:59', new \DateTimeZone('Europe/Prague'))));
        self::assertFalse($period->contains($this->day('2026-11-23')));
    }

    /**
     * @param list<ForecastPeriod> $periods
     *
     * @return list<string>
     */
    private function describe(array $periods): array
    {
        return array_map(static fn (ForecastPeriod $period): string => \sprintf('%s %s %s %s', $period->kind->value, $period->key(), $period->start->format('Y-m-d'), $period->end->format('Y-m-d')), $periods);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }
}
