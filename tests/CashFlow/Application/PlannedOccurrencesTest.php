<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Application\SkipPlannedOccurrenceInput;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * The occurrence whose period holds a day, for items that repeat every few months or every week.
 */
final class PlannedOccurrencesTest extends CashFlowTestCase
{
    public function testAnItemEveryThreeMonthsHoldsTheThreeCalendarMonthsEndingWithTheOccurrence(): void
    {
        $item = $this->planned(on: '2026-02-20', repeat: 'month', every: 3);
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        // Occurrences 20 Feb, 20 May, 20 Aug; the one on 20 May holds March, April and May.
        self::assertSame('2026-05-20', $occurrences->holding($item, new \DateTimeImmutable('2026-03-01'))?->format('Y-m-d'));
        self::assertSame('2026-05-20', $occurrences->holding($item, new \DateTimeImmutable('2026-05-31'))?->format('Y-m-d'));
        self::assertSame('2026-08-20', $occurrences->holding($item, new \DateTimeImmutable('2026-06-01'))?->format('Y-m-d'));
        self::assertSame('2026-02-20', $occurrences->holding($item, new \DateTimeImmutable('2026-02-02'))?->format('Y-m-d'));
    }

    public function testAWeeklyItemHoldsTheIsoWeekOfItsOccurrence(): void
    {
        // Friday 2026-05-22 repeats every week.
        $item = $this->planned(on: '2026-05-22', repeat: 'week');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        self::assertSame('2026-05-29', $occurrences->holding($item, new \DateTimeImmutable('2026-05-25'))?->format('Y-m-d'), 'Monday of the week of 29 May.');
        self::assertSame('2026-05-29', $occurrences->holding($item, new \DateTimeImmutable('2026-05-31'))?->format('Y-m-d'), 'Sunday of the same week.');
        self::assertSame('2026-06-05', $occurrences->holding($item, new \DateTimeImmutable('2026-06-01'))?->format('Y-m-d'));
        self::assertNull($occurrences->holding($item, new \DateTimeImmutable('2026-05-10')), 'Before the first occurrence.');
    }

    public function testNoOccurrenceHoldsADayBeforeTheFirstOne(): void
    {
        $item = $this->planned(on: '2026-05-20', repeat: 'month');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        self::assertNull($occurrences->holding($item, new \DateTimeImmutable('2026-04-10')));
        self::assertSame('2026-05-20', $occurrences->holding($item, new \DateTimeImmutable('2026-05-02'))?->format('Y-m-d'));
    }

    public function testAnItemEveryFewDaysHoldsTheDaysEndingWithTheOccurrence(): void
    {
        $item = $this->planned(on: '2026-05-01', repeat: 'day', every: 3);
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        // Occurrences 1, 4 and 7 May; the one on 7 May holds the 5th, the 6th and the 7th.
        self::assertSame('2026-05-07', $occurrences->holding($item, new \DateTimeImmutable('2026-05-05'))?->format('Y-m-d'));
        self::assertSame('2026-05-07', $occurrences->holding($item, new \DateTimeImmutable('2026-05-07'))?->format('Y-m-d'));
        self::assertSame('2026-05-04', $occurrences->holding($item, new \DateTimeImmutable('2026-05-02'))?->format('Y-m-d'));
    }

    public function testAnItemEveryTwoYearsHoldsTheTwoCalendarYearsEndingWithTheOccurrence(): void
    {
        $item = $this->planned(on: '2024-03-15', repeat: 'year', every: 2);
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        // Occurrences 2024, 2026 and 2028; the one in 2026 holds 2025 and 2026.
        self::assertSame('2026-03-15', $occurrences->holding($item, new \DateTimeImmutable('2025-02-01'))?->format('Y-m-d'));
        self::assertSame('2026-03-15', $occurrences->holding($item, new \DateTimeImmutable('2026-11-02'))?->format('Y-m-d'));
        self::assertSame('2024-03-15', $occurrences->holding($item, new \DateTimeImmutable('2024-12-31'))?->format('Y-m-d'));
    }

    public function testAnItemFallsOnTheDaysOfItsScheduleOnly(): void
    {
        $item = $this->planned(on: '2026-05-20', repeat: 'month');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);
        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-07-20'));

        self::assertTrue($occurrences->occursOn($item, new \DateTimeImmutable('2026-05-20')), 'The first day.');
        self::assertTrue($occurrences->occursOn($item, new \DateTimeImmutable('2026-06-20')));
        self::assertFalse($occurrences->occursOn($item, new \DateTimeImmutable('2026-06-21')));
        self::assertFalse($occurrences->occursOn($item, new \DateTimeImmutable('2026-04-20')), 'The month before the first day.');
        self::assertFalse($occurrences->occursOn($item, new \DateTimeImmutable('2026-07-20')), 'Skipped.');
        // 23:30 UTC on 19 June is 20 June in Prague.
        self::assertTrue($occurrences->occursOn($item, new \DateTimeImmutable('2026-06-19 23:30:00', new \DateTimeZone('UTC'))));
    }

    public function testAOneOffItemFallsOnItsDayOnly(): void
    {
        $item = $this->planned(on: '2026-11-20');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        self::assertTrue($occurrences->occursOn($item, new \DateTimeImmutable('2026-11-20')));
        self::assertFalse($occurrences->occursOn($item, new \DateTimeImmutable('2026-11-21')));
    }

    public function testTheDaysBetweenTwoDaysIncludeBothAndLeaveOutSkippedOnes(): void
    {
        $item = $this->planned(on: '2026-05-20', repeat: 'month');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);
        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-07-20'));

        self::assertSame(['2026-06-20', '2026-08-20'], $this->days($occurrences->between($item, new \DateTimeImmutable('2026-06-20'), new \DateTimeImmutable('2026-08-20'))));
        self::assertSame(['2026-05-20', '2026-06-20'], $this->days($occurrences->between($item, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-07-31'))), 'Nothing before the first day.');
    }

    public function testTheDaysBetweenAreOnlyThoseInTheRangeAndAList(): void
    {
        $item = $this->planned(on: '2026-05-20', repeat: 'month');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        $inside = $occurrences->between($item, new \DateTimeImmutable('2026-06-21'), new \DateTimeImmutable('2026-08-19'));
        $lastDay = $occurrences->between($item, new \DateTimeImmutable('2026-06-21'), new \DateTimeImmutable('2026-07-20'));

        self::assertSame(['2026-07-20'], $this->days($inside));
        self::assertSame([0], array_keys($inside));
        self::assertSame(['2026-07-20'], $this->days($lastDay));
    }

    public function testTheDaysBetweenOfAOneOffItemAreItsDayWhenInRange(): void
    {
        $item = $this->planned(on: '2026-11-20');
        $occurrences = self::getContainer()->get(PlannedOccurrences::class);

        self::assertSame(['2026-11-20'], $this->days($occurrences->between($item, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'))));
        self::assertSame([], $occurrences->between($item, new \DateTimeImmutable('2026-11-21'), new \DateTimeImmutable('2026-11-30')));
        self::assertSame([], $occurrences->between($item, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-19')));
    }

    /**
     * @param list<\DateTimeImmutable> $days
     *
     * @return list<string>
     */
    private function days(array $days): array
    {
        return array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $days);
    }
}
