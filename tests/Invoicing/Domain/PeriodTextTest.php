<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\PeriodText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodTextTest extends TestCase
{
    public function testPlainTokens(): void
    {
        self::assertSame(
            'Hosting 7. 3. 2027',
            PeriodText::resolve('Hosting {day}. {month}. {year}', new \DateTimeImmutable('2027-03-07')),
        );
    }

    public function testPeriodTextOffsetsCarry(): void
    {
        self::assertSame('1/2027', PeriodText::resolve('{month+1}/{year}', new \DateTimeImmutable('2026-12-01')));
        self::assertSame('2027-1', PeriodText::resolve('{year}-{month+1}', new \DateTimeImmutable('2026-12-01')));
        self::assertSame('11/2026', PeriodText::resolve('{month-1}/{year}', new \DateTimeImmutable('2026-12-01')));
        self::assertSame('12/2026', PeriodText::resolve('{month-1}/{year}', new \DateTimeImmutable('2027-01-15')));
        self::assertSame('12/2025', PeriodText::resolve('{month-13}/{year}', new \DateTimeImmutable('2027-01-15')));
        self::assertSame('1/2028', PeriodText::resolve('{month+25}/{year}', new \DateTimeImmutable('2025-12-31')));
    }

    public function testEachOffsetCarriesOnlyItsOwnCoarserNeighbours(): void
    {
        self::assertSame(
            'from 11/2026 to 1/2027',
            PeriodText::resolve('from {month-1}/{year} to {month+1}/{year}', new \DateTimeImmutable('2026-12-01')),
        );
        self::assertSame(
            '12 and 1/2027',
            PeriodText::resolve('{month} and {month+1}/{year}', new \DateTimeImmutable('2026-12-01')),
        );
    }

    public function testDayOffsetCarriesIntoMonthAndYear(): void
    {
        self::assertSame('1. 1. 2027', PeriodText::resolve('{day+1}. {month}. {year}', new \DateTimeImmutable('2026-12-31')));
        self::assertSame('29. 2. 2028', PeriodText::resolve('{day-1}. {month}. {year}', new \DateTimeImmutable('2028-03-01')));
        self::assertSame('1', PeriodText::resolve('{day+1}', new \DateTimeImmutable('2027-02-28')));
    }

    public function testYearOffset(): void
    {
        self::assertSame('12/2025', PeriodText::resolve('{month}/{year-1}', new \DateTimeImmutable('2026-12-01')));
        self::assertSame('2029', PeriodText::resolve('{year+3}', new \DateTimeImmutable('2026-12-01')));
    }

    public function testMonthYearToken(): void
    {
        self::assertSame('3/2027', PeriodText::resolve('{month/year}', new \DateTimeImmutable('2027-03-01')));
        self::assertSame('1/2027', PeriodText::resolve('{month/year+1}', new \DateTimeImmutable('2026-12-31')));
        self::assertSame('12/2026', PeriodText::resolve('{month/year-1}', new \DateTimeImmutable('2027-01-31')));
        self::assertSame('Q1 1/2027', PeriodText::resolve('Q{quarter} {month/year+1}', new \DateTimeImmutable('2026-12-31')));
    }

    public function testQuarterAndDay(): void
    {
        self::assertSame('1', PeriodText::resolve('{quarter}', new \DateTimeImmutable('2027-03-31')));
        self::assertSame('2', PeriodText::resolve('{quarter}', new \DateTimeImmutable('2027-04-01')));
        self::assertSame('4', PeriodText::resolve('{quarter}', new \DateTimeImmutable('2027-12-31')));
        self::assertSame('Q1/2027', PeriodText::resolve('Q{quarter+1}/{year}', new \DateTimeImmutable('2026-11-30')));
        self::assertSame('Q4/2026', PeriodText::resolve('Q{quarter-1}/{year}', new \DateTimeImmutable('2027-02-28')));
        self::assertSame('31', PeriodText::resolve('{day}', new \DateTimeImmutable('2027-01-31')));
        self::assertSame('Q1', PeriodText::resolve('Q{quarter}', new \DateTimeImmutable('2027-01-01')));
    }

    public function testMonthOffsetCarriesIntoQuarter(): void
    {
        self::assertSame('1 Q1/2027', PeriodText::resolve('{month+1} Q{quarter}/{year}', new \DateTimeImmutable('2026-12-01')));
        self::assertSame('Q4/2026', PeriodText::resolve('Q{quarter}/{year}', new \DateTimeImmutable('2026-12-01')));
    }

    public function testUsesTheOccurrenceCalendarDayWhateverTheTime(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');

        self::assertSame('1/2027', PeriodText::resolve('{month/year}', new \DateTimeImmutable('2027-01-01 00:30', $zone)));
        self::assertSame('31. 3. 2027', PeriodText::resolve('{day+3}. {month}. {year}', new \DateTimeImmutable('2027-03-28', $zone)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'unknown and unclosed' => ['{foo} {month'];
        yield 'empty braces' => ['{}'];
        yield 'capitals' => ['{Month} {YEAR}'];
        yield 'space inside' => ['{ month }'];
        yield 'sign without number' => ['{month+}'];
        yield 'letters after sign' => ['{month+x}'];
        yield 'double sign' => ['{month++1}'];
        yield 'czech words' => ['{mesic}/{rok}'];
        yield 'series format' => ['{YYYY}{NNNN}'];
        yield 'reversed slash' => ['{year/month}'];
        yield 'closing only' => ['month} year}'];
        yield 'plain' => ['Web hosting'];
        yield 'empty' => [''];
    }

    #[DataProvider('untouched')]
    public function testUnknownBracesStayAsTyped(string $text): void
    {
        self::assertSame($text, PeriodText::resolve($text, new \DateTimeImmutable('2027-03-01')));
    }

    public function testKnownTokensResolveNextToUnknownOnes(): void
    {
        self::assertSame(
            '{foo} 3/2027 {month',
            PeriodText::resolve('{foo} {month}/{year} {month', new \DateTimeImmutable('2027-03-01')),
        );
        self::assertSame('{3}', PeriodText::resolve('{{month}}', new \DateTimeImmutable('2027-03-01')));
    }

    public function testTokensListsTheFiveVariables(): void
    {
        self::assertSame(
            '7 3 2027 1 3/2027',
            PeriodText::resolve(implode(' ', PeriodText::TOKENS), new \DateTimeImmutable('2027-03-07')),
        );
    }
}
