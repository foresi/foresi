<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Work\Application\View\CalendarPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The days of a calendar of due dates: a month in whole weeks from Monday, or one week.
 */
final class CalendarPeriodTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-11-27 15:30');
    }

    public function testAMonthIsItsWholeWeeksFromMonday(): void
    {
        $period = CalendarPeriod::of('', '', $this->today);

        self::assertSame(CalendarPeriod::MONTH, $period->period);
        self::assertSame(['2026-11-01', '2026-10-26', '2026-12-06'], [$period->anchor->format('Y-m-d'), $period->from->format('Y-m-d'), $period->to->format('Y-m-d')]);
        $weeks = $period->weeks();
        self::assertCount(6, $weeks);
        self::assertSame(['2026-10-26', '2026-11-01'], [$weeks[0][0]->format('Y-m-d'), $weeks[0][6]->format('Y-m-d')]);
        self::assertSame('2026-12-06', $weeks[5][6]->format('Y-m-d'));
        self::assertFalse($period->owns($weeks[0][0]));
        self::assertTrue($period->owns($weeks[0][6]));
        self::assertSame(['2026-10-01', '2026-12-01'], [$period->previous(), $period->next()]);
    }

    public function testAMonthThatStartsOnMondayHasNoWeekBefore(): void
    {
        $period = CalendarPeriod::of('month', '2027-02-14', $this->today);

        self::assertSame(['2027-02-01', '2027-02-28'], [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')]);
        self::assertCount(4, $period->weeks());
        self::assertSame(['2027-01-01', '2027-03-01'], [$period->previous(), $period->next()]);
    }

    public function testAWeekRunsFromMondayToSunday(): void
    {
        $period = CalendarPeriod::of('week', '2026-11-29', $this->today);

        self::assertSame(['2026-11-23', '2026-11-29'], [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')]);
        self::assertCount(1, $period->weeks());
        self::assertCount(7, $period->weeks()[0]);
        self::assertTrue($period->owns(new \DateTimeImmutable('2026-12-30')));
        self::assertSame(['2026-11-16', '2026-11-30'], [$period->previous(), $period->next()]);
    }

    public function testWithoutTheWeekendAWeekHoldsMondayToFriday(): void
    {
        $period = CalendarPeriod::of('week', '2026-11-27', $this->today, false);

        $days = array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $period->weeks()[0]);
        self::assertSame(['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'], $days);
        // The range is still the whole week, so what falls on the weekend can be counted.
        self::assertSame('2026-11-29', $period->to->format('Y-m-d'));
        self::assertTrue(CalendarPeriod::isWeekend(new \DateTimeImmutable('2026-11-28')));
        self::assertFalse(CalendarPeriod::isWeekend(new \DateTimeImmutable('2026-11-27')));
    }

    public function testAnUnknownPeriodOrDayOpensThisMonth(): void
    {
        foreach ([['day', '2026-11-05'], ['month', '2026-13-01'], ['month', '27.11.2026'], ['month', '2026-02-30']] as [$name, $on]) {
            $period = CalendarPeriod::of($name, $on, $this->today);
            self::assertSame(['month', '2026-11-01'], [$period->period, $period->anchor->format('Y-m-d')], $name.' '.$on);
        }
    }
}
