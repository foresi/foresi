<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\InvalidFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string, string, string}>
     */
    public static function values(): iterable
    {
        // Wednesday 2026-11-18 in Prague; a range runs from its first day to the day after its last.
        $today = '2026-11-18';
        yield 'a day' => ['2026-10-05', $today, '2026-10-05', '2026-10-06'];
        yield 'a month' => [['month' => '2026-02'], $today, '2026-02-01', '2026-03-01'];
        yield 'today' => [['relative' => 'today'], $today, '2026-11-18', '2026-11-19'];
        yield 'yesterday' => [['relative' => 'yesterday'], $today, '2026-11-17', '2026-11-18'];
        yield 'tomorrow' => [['relative' => 'tomorrow'], $today, '2026-11-19', '2026-11-20'];
        yield 'this week from Monday' => [['relative' => 'this_week'], $today, '2026-11-16', '2026-11-23'];
        yield 'this week on a Sunday' => [['relative' => 'this_week'], '2026-11-22', '2026-11-16', '2026-11-23'];
        yield 'this week on a Monday' => [['relative' => 'this_week'], '2026-11-16', '2026-11-16', '2026-11-23'];
        yield 'last week' => [['relative' => 'last_week'], $today, '2026-11-09', '2026-11-16'];
        yield 'this month' => [['relative' => 'this_month'], $today, '2026-11-01', '2026-12-01'];
        yield 'last month' => [['relative' => 'last_month'], $today, '2026-10-01', '2026-11-01'];
        yield 'last month in January' => [['relative' => 'last_month'], '2027-01-15', '2026-12-01', '2027-01-01'];
        yield 'last month on the 31st' => [['relative' => 'last_month'], '2026-03-31', '2026-02-01', '2026-03-01'];
        yield 'next month' => [['relative' => 'next_month'], '2026-12-31', '2027-01-01', '2027-02-01'];
        yield 'this quarter' => [['relative' => 'this_quarter'], $today, '2026-10-01', '2027-01-01'];
        yield 'last quarter' => [['relative' => 'last_quarter'], $today, '2026-07-01', '2026-10-01'];
        yield 'last quarter in February' => [['relative' => 'last_quarter'], '2026-02-10', '2025-10-01', '2026-01-01'];
        yield 'this year' => [['relative' => 'this_year'], $today, '2026-01-01', '2027-01-01'];
        yield 'last year' => [['relative' => 'last_year'], $today, '2025-01-01', '2026-01-01'];
        yield 'the last 7 days end today' => [['relative' => 'last_n_days', 'n' => 7], $today, '2026-11-12', '2026-11-19'];
        yield 'the next 7 days start today' => [['relative' => 'next_n_days', 'n' => 7], $today, '2026-11-18', '2026-11-25'];
        yield '14 days ago' => [['relative' => 'n_days_ago', 'n' => 14], $today, '2026-11-04', '2026-11-05'];
        yield '30 days from now' => [['relative' => 'n_days_from_now', 'n' => 30], $today, '2026-12-18', '2026-12-19'];
    }

    #[DataProvider('values')]
    public function testAValueCoversItsDaysOnTheDayGiven(mixed $value, string $today, string $start, string $end): void
    {
        $range = DateRange::of($value, new \DateTimeImmutable($today.' 00:00', new \DateTimeZone('Europe/Prague')));

        self::assertSame([$start, $end], [$range->start->format('Y-m-d'), $range->end->format('Y-m-d')]);
        self::assertSame('Europe/Prague', $range->start->getTimezone()->getName());
    }

    public function testTodayIsMidnightOfTheDayGivenWhateverItsTime(): void
    {
        // InstanceClock::today() is midnight already; a later moment of the day is the same day.
        $range = DateRange::of(['relative' => 'today'], new \DateTimeImmutable('2026-11-18 23:59', new \DateTimeZone('Europe/Prague')));

        self::assertSame('2026-11-18 00:00', $range->start->format('Y-m-d H:i'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no day' => ['2026-02-30', 'value'];
        yield 'not a date' => [12, 'value'];
        yield 'a bad month' => [['month' => '2026-13'], 'value.month'];
        yield 'an unknown rule' => [['relative' => 'someday'], 'value.relative'];
        yield 'a period with a count' => [['relative' => 'today', 'n' => 3], 'value'];
        yield 'a count missing' => [['relative' => 'last_n_days'], 'value.n'];
        yield 'a count too high' => [['relative' => 'last_n_days', 'n' => 366], 'value.n'];
        yield 'a count of zero' => [['relative' => 'n_days_ago', 'n' => 0], 'value.n'];
    }

    #[DataProvider('invalid')]
    public function testAValueItCannotResolveIsRefusedWithItsPath(mixed $value, string $path): void
    {
        try {
            DateRange::check($value, 'value');
            self::fail('The value is refused.');
        } catch (InvalidFilter $refused) {
            self::assertSame($path, $refused->path);
        }
    }
}
