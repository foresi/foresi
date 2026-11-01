<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\InvalidSeriesFormat;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\VariableSymbol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeriesFormatTest extends TestCase
{
    #[DataProvider('rendered')]
    public function testRenders(string $format, string $date, int $sequence, string $number): void
    {
        self::assertSame($number, SeriesFormat::fromString($format)->render(new \DateTimeImmutable($date), $sequence));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function rendered(): iterable
    {
        yield 'the default' => ['{YYYY}{NNNN}', '2026-10-31', 89, '20260089'];
        yield 'text and a short year' => ['FV{YY}-{NNN}', '2026-10-31', 89, 'FV26-089'];
        yield 'never truncated' => ['{NNNN}', '2026-10-31', 10000, '10000'];
        yield 'month and day' => ['{YYYY}/{MM}/{DD}/{N}', '2026-02-03', 7, '2026/02/03/7'];
        yield 'the sequence first' => ['{NN}.{YY}', '2026-01-01', 3, '03.26'];
    }

    #[DataProvider('sequences')]
    public function testFindsTheSequenceInANumber(string $format, string $number, ?int $sequence): void
    {
        self::assertSame($sequence, SeriesFormat::fromString($format)->sequenceIn($number, new \DateTimeImmutable('2026-10-31')));
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function sequences(): iterable
    {
        yield 'the default' => ['{YYYY}{NNNN}', '20260089', 89];
        yield 'above the padding' => ['{YYYY}{NNNN}', '202610000', 10000];
        yield 'not a number of the series' => ['{YYYY}{NNNN}', 'ABC', null];
        yield 'another year' => ['{YYYY}{NNNN}', '20250089', null];
        yield 'shorter than the padding' => ['{YYYY}{NNNN}', '2026089', null];
        yield 'text around it' => ['FV{YY}-{NNN}', 'FV26-089', 89];
        yield 'other case of the same number' => ['FV{YY}-{NNN}', 'fv26-089', 89];
        yield 'a dot is no wildcard' => ['A.{N}', 'AB1', null];
        yield 'punctuation added' => ['{YYYY}{NNNN}', '2026-0095', 95];
        yield 'punctuation and case' => ['FV{YY}-{NNN}', 'fv-26/095', 95];
        yield 'punctuation left out' => ['FV{YY}-{NNN}', 'FV26095', 95];
        yield 'only the key counts' => ['{NNN}', "089\n", 89];
        yield 'the largest sequence of the counter' => ['{N}', '2147483647', 2147483647];
        yield 'above the counter' => ['{N}', '2147483648', null];
        yield 'too long for a sequence' => ['{N}', '1234567890123456789012', null];
        yield 'month of the date' => ['{MM}-{N}', '10-5', 5];
        yield 'another month' => ['{MM}-{N}', '09-5', null];
    }

    public function testThePeriodIsTheYearWhenAYearTokenExists(): void
    {
        $date = new \DateTimeImmutable('2026-10-31');

        self::assertSame('2026', SeriesFormat::fromString('{YYYY}{NNNN}')->period($date));
        self::assertSame('2026', SeriesFormat::fromString('{YY}{NNNN}')->period($date));
        self::assertSame('all', SeriesFormat::fromString('{MM}{NNNN}')->period($date));
        self::assertSame('all', SeriesFormat::fromString('{NNNN}')->period($date));
    }

    #[DataProvider('refused')]
    public function testRefuses(string $format): void
    {
        $this->expectException(InvalidSeriesFormat::class);

        SeriesFormat::fromString($format);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'no sequence' => ['{YYYY}'];
        yield 'two sequences' => ['{NN}{NNN}'];
        yield 'an unknown token' => ['{YYYY}{Q}{NN}'];
        yield 'lower case token' => ['{nnn}'];
        yield 'an empty token' => ['{}{N}'];
        yield 'an open brace' => ['{N}{'];
        yield 'a closing brace' => ['}{N}'];
        yield 'nested braces' => ['{{N}}'];
        yield 'a space' => ['FV {N}'];
        yield 'a line break' => ["{N}\n"];
        yield 'a letter the key would drop' => ['Č{N}'];
        yield 'too long' => [str_repeat('A', 62).'{N}'];
    }

    public function testKeepsItsSource(): void
    {
        self::assertSame('FV{YY}-{NNN}', SeriesFormat::fromString('FV{YY}-{NNN}')->toString());
        self::assertSame(str_repeat('A', 61).'{N}', SeriesFormat::fromString(str_repeat('A', 61).'{N}')->toString());
    }

    public function testTwoFormatsCollideWhenTheirNumbersShareAKey(): void
    {
        $format = SeriesFormat::fromString('FV{YY}{NNN}');

        self::assertTrue($format->collidesWith(SeriesFormat::fromString('FV-{YY}/{NNN}')));
        self::assertFalse($format->collidesWith(SeriesFormat::fromString('ZV{YY}{NNN}')));
        self::assertFalse($format->collidesWith(SeriesFormat::fromString('FV{YYYY}{NNN}')));
    }

    public function testTheWidthOfTheSequenceDoesNotKeepNumbersApart(): void
    {
        self::assertTrue(SeriesFormat::fromString('{YYYY}{NNNN}')->collidesWith(SeriesFormat::fromString('{YYYY}{NNN}')));
        self::assertTrue(SeriesFormat::fromString('FV-{N}')->collidesWith(SeriesFormat::fromString('fv{NNNNNN}')));
    }

    public function testTheNumberKeyIsUpperCaseLettersAndDigits(): void
    {
        self::assertSame('FV26001', NumberKey::of('fv-26/001'));
        self::assertSame('20260089', NumberKey::of('20260089'));
        self::assertSame('A1', NumberKey::of(' a č 1 '));
    }

    public function testTheVariableSymbolIsTheDigitsThatFit(): void
    {
        self::assertSame('920260002', VariableSymbol::fromNumber('Z920260002'));
        self::assertSame('20260089', VariableSymbol::fromNumber('20260089'));
        self::assertSame('1234567890', VariableSymbol::fromNumber('1234567890'));
        self::assertSame('', VariableSymbol::fromNumber('12345678901'));
        self::assertSame('', VariableSymbol::fromNumber('FV-2026/000000089'));
    }
}
