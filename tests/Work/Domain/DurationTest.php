<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Work\Domain\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    #[DataProvider('typed')]
    public function testADurationIsReadAsAPersonTypesIt(string $text, int $minutes): void
    {
        self::assertSame($minutes, Duration::parse($text)->minutes);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function typed(): iterable
    {
        yield 'hours and minutes' => ['1:30', 90];
        yield 'minutes only on the clock' => ['0:45', 45];
        yield 'a whole day on the clock' => ['24:00', 1440];
        yield 'decimal comma' => ['1,5', 90];
        yield 'decimal point' => ['1.5', 90];
        yield 'a bare number is hours' => ['2', 120];
        yield 'a quarter of an hour' => ['0,25', 15];
        yield 'a fraction rounded to the nearest minute' => ['0.01', 1];
        yield 'hours and minutes spelled out' => ['1h 30m', 90];
        yield 'without a space' => ['1h30m', 90];
        yield 'minutes spelled out' => ['45m', 45];
        yield 'minutes as min' => ['45 min', 45];
        yield 'hours spelled out' => ['2h', 120];
        yield 'decimal hours spelled out' => ['1,5h', 90];
        yield 'many minutes spelled out' => ['90m', 90];
        yield 'capitals and spaces around' => ['  1H 30M ', 90];
        yield 'a no-break space' => ["1h\u{00A0}30m", 90];
        yield 'a whole day' => ['24', 1440];
    }

    #[DataProvider('refused')]
    public function testWhatIsNoDurationNothingOrMoreThanADayIsRefused(string $text): void
    {
        $this->expectException(\DomainException::class);
        Duration::parse($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'zero' => ['0'];
        yield 'zero on the clock' => ['0:00'];
        yield 'zero minutes' => ['0m'];
        yield 'negative' => ['-1'];
        yield 'negative on the clock' => ['-1:30'];
        yield 'more than a day' => ['24,5'];
        yield 'more than a day on the clock' => ['24:01'];
        yield 'more than a day spelled out' => ['25h'];
        yield 'minutes past sixty on the clock' => ['1:60'];
        yield 'one digit of minutes' => ['1:5'];
        yield 'words' => ['an hour'];
        yield 'units in the wrong order' => ['30m 1h'];
        yield 'two separators' => ['1.5.2'];
    }

    public function testTheLimitCanBeRaisedForAnEstimate(): void
    {
        self::assertSame(40 * 60, Duration::parse('40', 9_999 * 60)->minutes);
        $this->expectException(\DomainException::class);
        Duration::parse('40');
    }

    public function testMinutesAreBetweenOneAndTheLimit(): void
    {
        self::assertSame([1, 1440], [Duration::ofMinutes(1)->minutes, Duration::ofMinutes(1440)->minutes]);
        foreach ([0, -5, 1441] as $minutes) {
            try {
                Duration::ofMinutes($minutes);
                self::fail(sprintf('%d minutes are refused.', $minutes));
            } catch (\DomainException) {
            }
        }
    }

    public function testASpanIsRoundedToTheNearestMinuteAndTheEndComesAfterTheStart(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 09:00:00');

        self::assertSame(90, Duration::between($start, $start->modify('+90 minutes'))->minutes);
        self::assertSame(2, Duration::between($start, $start->modify('+90 seconds'))->minutes);
        self::assertSame(1, Duration::between($start, $start->modify('+10 seconds'))->minutes);
        self::assertSame(1, Duration::spanMinutes($start, $start->modify('-1 hour')));
        foreach ([$start, $start->modify('-1 minute'), $start->modify('+1441 minutes')] as $end) {
            try {
                Duration::between($start, $end);
                self::fail('The span is refused.');
            } catch (\DomainException) {
            }
        }
    }
}
