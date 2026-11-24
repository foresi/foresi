<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\ReadFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['12100.00', 1_210_000];
        yield 'one decimal' => ['5.5', 550];
        yield 'whole' => [' 100 ', 10_000];
        yield 'trailing zeros' => ['1.500', 150];
        yield 'zero' => ['0', 0];
        yield 'too many decimals' => ['1.005', null];
        yield 'comma' => ['1,50', null];
        yield 'negative' => ['-1.00', null];
        yield 'sign' => ['+1.00', null];
        yield 'letters' => ['12 1oo', null];
        yield 'empty' => ['', null];
        yield 'exponent' => ['1e3', null];
        yield 'too large' => ['99999999999999999999.00', null];
    }

    #[DataProvider('amounts')]
    public function testAmountToMinorUnits(string $text, ?int $expected): void
    {
        self::assertSame($expected, ReadFormat::minor($text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function dates(): iterable
    {
        yield 'iso' => ['2026-10-31', '2026-10-31'];
        yield 'iso with zone' => ['2026-10-31+01:00', '2026-10-31'];
        yield 'iso with Z' => ['2026-10-31Z', '2026-10-31'];
        yield 'compact' => ['20261031', '2026-10-31'];
        yield 'not a day' => ['2026-02-30', null];
        yield 'czech' => ['31.10.2026', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('dates')]
    public function testDate(string $text, ?string $expected): void
    {
        self::assertSame($expected, ReadFormat::date($text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function rates(): iterable
    {
        yield 'with zeros' => ['21.00', '21'];
        yield 'whole' => ['21', '21'];
        yield 'fraction' => ['10.50', '10.5'];
        yield 'zero' => ['0.00', '0'];
        yield 'over 100' => ['101', null];
        yield 'negative' => ['-5', null];
        yield 'text' => ['standard', null];
    }

    #[DataProvider('rates')]
    public function testRate(string $text, ?string $expected): void
    {
        self::assertSame($expected, ReadFormat::rate($text));
    }

    public function testTextIsTrimmedCleanedAndCapped(): void
    {
        self::assertSame('Cloud Host', ReadFormat::text("  Cloud\x00 Host\n ", 255));
        self::assertSame('abc', ReadFormat::text('abcdef', 3));
        self::assertNull(ReadFormat::text('   ', 255));
    }

    public function testVatId(): void
    {
        self::assertSame('CZ12345678', ReadFormat::vatId(' cz 12345678 '));
        self::assertNull(ReadFormat::vatId('not-a-vat-id!'));
    }
}
