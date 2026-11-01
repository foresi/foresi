<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\LineTextDates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineTextDatesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'month and year with a slash' => ['Hosting 10/2026', 'cs'];
        yield 'single digit month' => ['Hosting 3/2026', 'cs'];
        yield 'month and year with a dot' => ['Hosting 10.2026', 'cs'];
        yield 'a whole date' => ['Servis 31.10.2026', 'cs'];
        yield 'a whole date with spaces' => ['Servis 31. 10. 2026', 'cs'];
        yield 'an ISO month' => ['Hosting 2026-10', 'cs'];
        yield 'an ISO date' => ['Hosting 2026-10-31', 'cs'];
        yield 'at the start of the text' => ['12/2026 hosting', 'cs'];
        yield 'Czech month' => ['Pronájem říjen 2026', 'cs'];
        yield 'Czech month in capitals' => ['PRONÁJEM ŘÍJEN', 'cs'];
        yield 'Czech month in the genitive' => ['Služby do 31. října', 'cs'];
        yield 'English month' => ['Rent for October 2026', 'en'];
        yield 'English month in lower case' => ['rent for december', 'en'];
    }

    #[DataProvider('dates')]
    public function testADateIsFound(string $text, string $locale): void
    {
        self::assertTrue(LineTextDates::found($text, $locale));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plainTexts(): iterable
    {
        yield 'a plain number' => ['2026 ks', 'cs'];
        yield 'no number' => ['Konzultace', 'cs'];
        yield 'empty' => ['', 'cs'];
        yield 'a month out of range' => ['13/2026', 'cs'];
        yield 'month zero' => ['0/2026', 'cs'];
        yield 'an ISO month out of range' => ['2026-13', 'cs'];
        yield 'ISO month zero' => ['2026-00', 'cs'];
        yield 'a long number before' => ['123/2026', 'cs'];
        yield 'a year beyond the pattern' => ['10/2100', 'cs'];
        yield 'a year of five digits' => ['10/20265', 'cs'];
        yield 'an order code' => ['Kód A-2026-104', 'cs'];
        yield 'a month inside a word' => ['Sprejgraf', 'en'];
        yield 'a Czech month in English text' => ['říjen', 'en'];
        yield 'a decimal with a comma' => ['1,10.2026x', 'cs'];
    }

    #[DataProvider('plainTexts')]
    public function testPlainTextIsNotADate(string $text, string $locale): void
    {
        self::assertFalse(LineTextDates::found($text, $locale));
    }
}
