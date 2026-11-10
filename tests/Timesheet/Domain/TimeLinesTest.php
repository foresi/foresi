<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Timesheet\Domain\BillableTime;
use App\Timesheet\Domain\LineGrouping;
use App\Timesheet\Domain\TimeLine;
use App\Timesheet\Domain\TimeLines;
use App\Timesheet\Domain\TimeRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lines time makes on an invoice: grouped as chosen, split by rate, each
 * entry rounded up before its line sums them, the hours to two decimals.
 */
final class TimeLinesTest extends TestCase
{
    /**
     * @return iterable<string, array{TimeRounding, int, int}>
     */
    public static function roundings(): iterable
    {
        yield 'none keeps a minute' => [TimeRounding::None, 1, 1];
        yield 'none keeps any minutes' => [TimeRounding::None, 97, 97];
        yield 'a quarter hour rounds a minute up' => [TimeRounding::UpToQuarterHour, 1, 15];
        yield 'a quarter hour keeps a whole quarter' => [TimeRounding::UpToQuarterHour, 30, 30];
        yield 'a quarter hour rounds just past one up' => [TimeRounding::UpToQuarterHour, 31, 45];
        yield 'a half hour rounds a minute up' => [TimeRounding::UpToHalfHour, 1, 30];
        yield 'a half hour keeps a whole hour' => [TimeRounding::UpToHalfHour, 60, 60];
        yield 'a half hour rounds just past an hour up' => [TimeRounding::UpToHalfHour, 61, 90];
    }

    #[DataProvider('roundings')]
    public function testAnEntryIsRoundedUpToTheStep(TimeRounding $rounding, int $worked, int $billed): void
    {
        self::assertSame($billed, $rounding->minutes($worked));
    }

    /**
     * The entries: a key, the project, the task, the person, the minutes and the rate.
     *
     * @return list<array{string, string, ?string, string, int, string}>
     */
    private function entries(): array
    {
        return [
            ['e1', 'Website', 'Design the homepage', 'Jana', 50, '1000 CZK'],
            ['e2', 'Website', 'Design the homepage', 'Petr', 20, '1000 CZK'],
            ['e3', 'Website', null, 'Jana', 10, '1000 CZK'],
            ['e4', 'Website', 'Write the copy', 'Jana', 40, '1200 CZK'],
            ['e5', 'Shop', 'Checkout', 'Jana', 25, '1000 CZK'],
            ['e6', 'Website', 'Design the homepage', 'Jana', 5, '1000 CZK'],
        ];
    }

    /**
     * The lines expected: their text, minutes, hours and amount, in order.
     *
     * @return iterable<string, array{LineGrouping, TimeRounding, list<array{string, int, string, string}>}>
     */
    public static function groupings(): iterable
    {
        yield 'per project' => [LineGrouping::Project, TimeRounding::None, [
            ['Shop', 25, '0.4200', '420.00 CZK'],
            ['Website', 85, '1.4200', '1420.00 CZK'],
            ['Website', 40, '0.6700', '804.00 CZK'],
        ]];
        yield 'per task, time without a task first' => [LineGrouping::Task, TimeRounding::None, [
            ['Shop – Checkout', 25, '0.4200', '420.00 CZK'],
            ['Website', 10, '0.1700', '170.00 CZK'],
            ['Website – Design the homepage', 75, '1.2500', '1250.00 CZK'],
            ['Website – Write the copy', 40, '0.6700', '804.00 CZK'],
        ]];
        yield 'per task, rounded to a quarter hour each' => [LineGrouping::Task, TimeRounding::UpToQuarterHour, [
            ['Shop – Checkout', 30, '0.5000', '500.00 CZK'],
            ['Website', 15, '0.2500', '250.00 CZK'],
            ['Website – Design the homepage', 105, '1.7500', '1750.00 CZK'],
            ['Website – Write the copy', 45, '0.7500', '900.00 CZK'],
        ]];
        yield 'per person' => [LineGrouping::Person, TimeRounding::None, [
            ['Shop – Jana', 25, '0.4200', '420.00 CZK'],
            ['Website – Jana', 65, '1.0800', '1080.00 CZK'],
            ['Website – Jana', 40, '0.6700', '804.00 CZK'],
            ['Website – Petr', 20, '0.3300', '330.00 CZK'],
        ]];
        yield 'per entry, rounded to a half hour each' => [LineGrouping::Entry, TimeRounding::UpToHalfHour, [
            ['Shop – Checkout – 5. 10. 2026', 30, '0.5000', '500.00 CZK'],
            ['Website – Design the homepage – 1. 10. 2026 – Note e1', 60, '1.0000', '1000.00 CZK'],
            ['Website – Design the homepage – 2. 10. 2026', 30, '0.5000', '500.00 CZK'],
            ['Website – 3. 10. 2026 – Note e3', 30, '0.5000', '500.00 CZK'],
            ['Website – Write the copy – 4. 10. 2026', 60, '1.0000', '1200.00 CZK'],
            ['Website – Design the homepage – 6. 10. 2026', 30, '0.5000', '500.00 CZK'],
        ]];
    }

    /**
     * @param list<array{string, int, string, string}> $expected
     */
    #[DataProvider('groupings')]
    public function testTheEntriesMakeTheLinesOfTheGrouping(LineGrouping $grouping, TimeRounding $rounding, array $expected): void
    {
        $lines = TimeLines::of($this->times(), $grouping, $rounding);

        self::assertSame($expected, array_map(static fn (TimeLine $line): array => [$line->text('j. n. Y'), $line->minutes(), $line->hours()->toDecimal(), self::money($line->amount())], $lines));
        self::assertSame(array_map(static fn (int $index): string => 'line-'.$index, range(1, \count($lines))), array_map(static fn (TimeLine $line): string => $line->key, $lines));
    }

    public function testEveryEntryLandsOnExactlyOneLineWithItsWorkedMinutesKept(): void
    {
        foreach (LineGrouping::cases() as $grouping) {
            $lines = TimeLines::of($this->times(), $grouping, TimeRounding::UpToQuarterHour);
            $entries = [];
            foreach ($lines as $line) {
                foreach ($line->entries as $billed) {
                    $entries[] = $billed->time->entryId;
                    self::assertSame(TimeRounding::UpToQuarterHour->minutes($billed->time->minutes), $billed->minutes);
                    self::assertTrue($billed->time->rate->equals($line->rate), 'a line holds one rate');
                }
            }
            sort($entries);
            self::assertSame(['e1', 'e2', 'e3', 'e4', 'e5', 'e6'], $entries, $grouping->value);
        }
    }

    public function testRatesInAnotherCurrencySplitALine(): void
    {
        $times = [self::time('e1', 'Website', null, 'Jana', 60, '1000 CZK'), self::time('e2', 'Website', null, 'Jana', 60, '40 EUR')];

        $lines = TimeLines::of($times, LineGrouping::Project, TimeRounding::None);

        self::assertSame(['1000.00 CZK', '40.00 EUR'], array_map(static fn (TimeLine $line): string => self::money($line->amount()), $lines));
    }

    public function testNoTimeMakesNoLines(): void
    {
        self::assertSame([], TimeLines::of([], LineGrouping::Task, TimeRounding::None));
    }

    /**
     * @return list<BillableTime>
     */
    private function times(): array
    {
        return array_map(static fn (array $entry): BillableTime => self::time(...$entry), $this->entries());
    }

    private static function time(string $key, string $project, ?string $task, string $person, int $minutes, string $rate): BillableTime
    {
        [$amount, $currency] = explode(' ', $rate);
        $day = (int) substr($key, 1);

        return new BillableTime(
            $key,
            'project-'.$project,
            $project,
            null === $task ? null : 'task-'.$task,
            $task,
            'person-'.$person,
            $person,
            new \DateTimeImmutable(\sprintf('2026-10-%02d', $day)),
            $minutes,
            \in_array($key, ['e1', 'e3'], true) ? 'Note '.$key : '',
            Money::of($amount, Currency::from($currency)),
        );
    }

    private static function money(Money $money): string
    {
        return \sprintf('%d.%02d %s', intdiv($money->minor(), 100), $money->minor() % 100, $money->currency()->value);
    }
}
