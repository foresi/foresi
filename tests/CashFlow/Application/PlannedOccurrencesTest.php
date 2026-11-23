<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\PlannedOccurrences;
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
}
