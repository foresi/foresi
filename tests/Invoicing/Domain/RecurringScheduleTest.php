<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\InvalidRecurrence;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringSchedule;
use App\Invoicing\Domain\TaxPointRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringScheduleTest extends TestCase
{
    public function testMonthlyFromThe31stClampsWithoutDrift(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Month);

        self::assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'],
            $this->dates($r, 0, 5),
        );
        self::assertSame('2027-12-31', $this->day(RecurringSchedule::occurrence($r, 11)));
        self::assertSame('2028-02-29', $this->day(RecurringSchedule::occurrence($r, 13)));
    }

    public function testMonthlyFromThe30thAndThe29th(): void
    {
        self::assertSame(
            ['2027-01-30', '2027-02-28', '2027-03-30'],
            $this->dates($this->recurrence('2027-01-30', RecurrenceUnit::Month), 0, 3),
        );
        self::assertSame(
            ['2028-01-29', '2028-02-29', '2028-03-29'],
            $this->dates($this->recurrence('2028-01-29', RecurrenceUnit::Month), 0, 3),
        );
    }

    public function testEveryThreeMonthsAcrossTheYear(): void
    {
        self::assertSame(
            ['2026-11-30', '2027-02-28', '2027-05-30', '2027-08-30', '2027-11-30', '2028-02-29'],
            $this->dates($this->recurrence('2026-11-30', RecurrenceUnit::Month, 3), 0, 6),
        );
    }

    public function testYearlyFromLeapDay(): void
    {
        $r = $this->recurrence('2028-02-29', RecurrenceUnit::Year);

        self::assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], $this->dates($r, 0, 5));
        self::assertSame('2100-02-28', $this->day(RecurringSchedule::occurrence($r, 72)));
    }

    public function testEveryTwelveMonthsEqualsYearlyDates(): void
    {
        $yearly = $this->recurrence('2028-02-29', RecurrenceUnit::Year);
        $twelve = $this->recurrence('2028-02-29', RecurrenceUnit::Month, 12);

        self::assertSame($this->dates($yearly, 0, 6), $this->dates($twelve, 0, 6));
        self::assertSame(RecurrenceUnit::Year, $yearly->unit());
        self::assertSame(1, $yearly->every());
        self::assertSame(RecurrenceUnit::Month, $twelve->unit());
        self::assertSame(12, $twelve->every());
    }

    public function testEveryTwoYears(): void
    {
        self::assertSame(
            ['2027-03-31', '2029-03-31', '2031-03-31'],
            $this->dates($this->recurrence('2027-03-31', RecurrenceUnit::Year, 2), 0, 3),
        );
    }

    public function testLastDayOfMonth(): void
    {
        $r = $this->recurrence('2027-01-15', RecurrenceUnit::Month, lastDay: true);

        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $this->dates($r, 0, 4));
        self::assertTrue($r->onLastDayOfMonth());

        $leap = $this->recurrence('2028-01-10', RecurrenceUnit::Month, lastDay: true);
        self::assertSame('2028-02-29', $this->day(RecurringSchedule::occurrence($leap, 1)));
    }

    public function testLastDayOfMonthYearly(): void
    {
        $r = $this->recurrence('2027-02-10', RecurrenceUnit::Year, lastDay: true);

        self::assertSame(['2027-02-28', '2028-02-29', '2029-02-28'], $this->dates($r, 0, 3));
    }

    public function testWeeklyAndDaily(): void
    {
        self::assertSame(
            ['2027-02-22', '2027-03-01', '2027-03-08'],
            $this->dates($this->recurrence('2027-02-22', RecurrenceUnit::Week), 0, 3),
        );
        self::assertSame(
            ['2027-12-30', '2028-01-13', '2028-01-27'],
            $this->dates($this->recurrence('2027-12-30', RecurrenceUnit::Week, 2), 0, 3),
        );
        self::assertSame(
            ['2028-02-28', '2028-02-29', '2028-03-01'],
            $this->dates($this->recurrence('2028-02-28', RecurrenceUnit::Day), 0, 3),
        );
        self::assertSame(
            ['2026-12-31', '2027-01-03', '2027-01-06'],
            $this->dates($this->recurrence('2026-12-31', RecurrenceUnit::Day, 3), 0, 3),
        );
    }

    public function testDstChangeKeepsLocalMidnight(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $weekly = new Recurrence(
            new \DateTimeImmutable('2027-03-22 05:00', $zone),
            RecurrenceUnit::Week,
            1,
            false,
            RecurrenceEnd::Never,
            null,
            null,
        );

        $spring = RecurringSchedule::occurrence($weekly, 1);
        self::assertSame('2027-03-29 00:00:00 Europe/Prague', $spring->format('Y-m-d H:i:s e'));

        $daily = new Recurrence(
            new \DateTimeImmutable('2027-10-30', $zone),
            RecurrenceUnit::Day,
            1,
            false,
            RecurrenceEnd::Never,
            null,
            null,
        );
        self::assertSame('2027-11-01 00:00:00 Europe/Prague', RecurringSchedule::occurrence($daily, 2)->format('Y-m-d H:i:s e'));
    }

    public function testFirstIssueDropsTheTime(): void
    {
        $r = $this->recurrence('2027-01-31 23:59:59', RecurrenceUnit::Month);

        self::assertSame('2027-01-31 00:00:00', $r->firstIssueOn()->format('Y-m-d H:i:s'));
        self::assertSame('2027-02-28 00:00:00', RecurringSchedule::occurrence($r, 1)->format('Y-m-d H:i:s'));
    }

    public function testNextSkipsIssuedAndPastDates(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Month);

        self::assertSame(
            ['2027-03-31', '2027-04-30'],
            array_map($this->day(...), RecurringSchedule::next($r, 2, new \DateTimeImmutable('2027-01-01'), 2)),
        );
        self::assertSame(
            ['2027-06-30', '2027-07-31', '2027-08-31'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2027-06-30'), 3)),
        );
        self::assertSame(
            ['2027-07-31'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2027-07-01 18:00'), 1)),
        );
        self::assertSame([], RecurringSchedule::next($r, 0, new \DateTimeImmutable('2027-07-01'), 0));
    }

    public function testNextFarAheadOfADailyAnchor(): void
    {
        $r = $this->recurrence('2026-01-01', RecurrenceUnit::Day);

        self::assertSame(
            ['2099-12-31', '2100-01-01'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2099-12-31'), 2)),
        );
    }

    public function testEndAfterCount(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Month, end: RecurrenceEnd::AfterCount, endAfter: 3);

        self::assertFalse(RecurringSchedule::isOver($r, 2, RecurringSchedule::occurrence($r, 2)));
        self::assertTrue(RecurringSchedule::isOver($r, 3, RecurringSchedule::occurrence($r, 3)));
        self::assertTrue(RecurringSchedule::isOver($r, 4, RecurringSchedule::occurrence($r, 4)));
        self::assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2026-01-01'), 10)),
        );
        self::assertSame(
            ['2027-03-31'],
            array_map($this->day(...), RecurringSchedule::next($r, 2, new \DateTimeImmutable('2026-01-01'), 10)),
        );
        self::assertSame([], RecurringSchedule::next($r, 3, new \DateTimeImmutable('2026-01-01'), 10));
    }

    public function testEndAfterOne(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Year, end: RecurrenceEnd::AfterCount, endAfter: 1);

        self::assertFalse(RecurringSchedule::isOver($r, 0, $r->firstIssueOn()));
        self::assertTrue(RecurringSchedule::isOver($r, 1, RecurringSchedule::occurrence($r, 1)));
    }

    public function testEndOnDateIncludesThatDay(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Month, end: RecurrenceEnd::OnDate, endOn: '2027-04-30');

        self::assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2027-01-01'), 10)),
        );
        self::assertFalse(RecurringSchedule::isOver($r, 3, new \DateTimeImmutable('2027-04-30 23:59:59')));
        self::assertTrue(RecurringSchedule::isOver($r, 4, RecurringSchedule::occurrence($r, 4)));
    }

    public function testEndOnDateComparesCalendarDaysAcrossZones(): void
    {
        $zone = new \DateTimeZone('Pacific/Auckland');
        $r = new Recurrence(
            new \DateTimeImmutable('2027-01-01', $zone),
            RecurrenceUnit::Day,
            1,
            false,
            RecurrenceEnd::OnDate,
            new \DateTimeImmutable('2027-01-03', new \DateTimeZone('UTC')),
            null,
        );

        self::assertSame(
            ['2027-01-01', '2027-01-02', '2027-01-03'],
            array_map($this->day(...), RecurringSchedule::next($r, 0, new \DateTimeImmutable('2026-12-01', $zone), 10)),
        );
    }

    public function testNeverEnds(): void
    {
        $r = $this->recurrence('2027-01-31', RecurrenceUnit::Month);

        self::assertFalse(RecurringSchedule::isOver($r, 998, RecurringSchedule::occurrence($r, 998)));
        self::assertCount(30, RecurringSchedule::next($r, 0, new \DateTimeImmutable('2027-01-01'), 30));
    }

    public function testTaxPointLastDayOfMonth(): void
    {
        self::assertSame('2027-02-28', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-02-01'))));
        self::assertSame('2028-02-29', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2028-02-29'))));
        self::assertSame('2027-12-31', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-12-15'))));
        self::assertSame('2027-04-30', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-04-30'))));
    }

    public function testTaxPointIssueDate(): void
    {
        $issued = new \DateTimeImmutable('2027-02-01 13:00', new \DateTimeZone('Europe/Prague'));

        self::assertSame('2027-02-01 00:00:00 Europe/Prague', TaxPointRule::IssueDate->for($issued)->format('Y-m-d H:i:s e'));
        self::assertSame('2027-02-28 00:00:00 Europe/Prague', TaxPointRule::LastDayOfMonth->for($issued)->format('Y-m-d H:i:s e'));
    }

    /**
     * @return iterable<string, array{RecurrenceUnit, int, bool, RecurrenceEnd, ?string, ?int}>
     */
    public static function invalid(): iterable
    {
        yield 'every zero' => [RecurrenceUnit::Month, 0, false, RecurrenceEnd::Never, null, null];
        yield 'every 100' => [RecurrenceUnit::Month, 100, false, RecurrenceEnd::Never, null, null];
        yield 'every negative' => [RecurrenceUnit::Day, -1, false, RecurrenceEnd::Never, null, null];
        yield 'last day on day' => [RecurrenceUnit::Day, 1, true, RecurrenceEnd::Never, null, null];
        yield 'last day on week' => [RecurrenceUnit::Week, 1, true, RecurrenceEnd::Never, null, null];
        yield 'end date missing' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::OnDate, null, null];
        yield 'end count missing' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::AfterCount, null, null];
        yield 'end count zero' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::AfterCount, null, 0];
        yield 'end count 1000' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::AfterCount, null, 1000];
        yield 'end date before the first' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::OnDate, '2027-01-30', null];
        yield 'never with a date' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, '2027-05-01', null];
        yield 'never with a count' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, 3];
        yield 'date with a count' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::OnDate, '2027-05-01', 3];
        yield 'count with a date' => [RecurrenceUnit::Month, 1, false, RecurrenceEnd::AfterCount, '2027-05-01', 3];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnInvalidRecurrence(RecurrenceUnit $unit, int $every, bool $lastDay, RecurrenceEnd $end, ?string $endOn, ?int $endAfter): void
    {
        $this->expectException(InvalidRecurrence::class);

        new Recurrence(
            new \DateTimeImmutable('2027-01-31'),
            $unit,
            $every,
            $lastDay,
            $end,
            null === $endOn ? null : new \DateTimeImmutable($endOn),
            $endAfter,
        );
    }

    public function testTheLimitsAreAllowed(): void
    {
        $first = $this->recurrence('2027-01-31', RecurrenceUnit::Month, 99, end: RecurrenceEnd::AfterCount, endAfter: 999);
        $same = $this->recurrence('2027-01-31', RecurrenceUnit::Day, end: RecurrenceEnd::OnDate, endOn: '2027-01-31');

        self::assertSame(99, $first->every());
        self::assertSame(999, $first->endAfter());
        self::assertSame(RecurrenceEnd::AfterCount, $first->end());
        self::assertNull($first->endOn());
        self::assertSame('2027-01-31', $this->day($same->endOn() ?? throw new \LogicException()));
        self::assertSame(['2027-01-31'], array_map($this->day(...), RecurringSchedule::next($same, 0, new \DateTimeImmutable('2027-01-01'), 5)));
    }

    public function testRefusesAnEndDateBeforeTheFirstOccurrence(): void
    {
        // The first occurrence of a last-day-of-month recurrence is 31 January, after the end date.
        try {
            $this->recurrence('2027-01-15', RecurrenceUnit::Month, lastDay: true, end: RecurrenceEnd::OnDate, endOn: '2027-01-20');
            self::fail('A recurrence that would never issue was accepted.');
        } catch (InvalidRecurrence $refused) {
            self::assertStringContainsString('first occurrence', $refused->getMessage());
        }

        $onTheDay = $this->recurrence('2027-01-15', RecurrenceUnit::Month, lastDay: true, end: RecurrenceEnd::OnDate, endOn: '2027-01-31');
        self::assertSame(['2027-01-31'], array_map($this->day(...), RecurringSchedule::next($onTheDay, 0, new \DateTimeImmutable('2027-01-01'), 5)));
    }

    public function testNextYearsAndWeeksAfterTheAnchorStartAtTheFirstDateNotBefore(): void
    {
        self::assertSame(
            ['2027-06-15', '2028-06-15'],
            array_map($this->day(...), RecurringSchedule::next($this->recurrence('2000-06-15', RecurrenceUnit::Year), 0, new \DateTimeImmutable('2027-06-14'), 2)),
        );
        self::assertSame(
            ['2027-01-04', '2027-01-11'],
            array_map($this->day(...), RecurringSchedule::next($this->recurrence('2026-01-05', RecurrenceUnit::Week), 0, new \DateTimeImmutable('2027-01-04'), 2)),
        );
    }

    public function testRefusesANegativeOccurrence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecurringSchedule::occurrence($this->recurrence('2027-01-31', RecurrenceUnit::Month), -1);
    }

    private function recurrence(
        string $first,
        RecurrenceUnit $unit,
        int $every = 1,
        bool $lastDay = false,
        RecurrenceEnd $end = RecurrenceEnd::Never,
        ?string $endOn = null,
        ?int $endAfter = null,
    ): Recurrence {
        return new Recurrence(
            new \DateTimeImmutable($first),
            $unit,
            $every,
            $lastDay,
            $end,
            null === $endOn ? null : new \DateTimeImmutable($endOn),
            $endAfter,
        );
    }

    /**
     * @return list<string>
     */
    private function dates(Recurrence $r, int $from, int $count): array
    {
        $dates = [];
        for ($n = $from; $n < $from + $count; ++$n) {
            $dates[] = $this->day(RecurringSchedule::occurrence($r, $n));
        }

        return $dates;
    }

    private function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
