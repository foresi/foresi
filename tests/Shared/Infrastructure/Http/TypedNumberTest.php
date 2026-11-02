<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\TypedNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypedNumber::class)]
final class TypedNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'empty' => ['', 'cs', ''];
        yield 'plain' => ['1000', 'cs', '1000'];
        yield 'Czech' => ['1 000,50', 'cs', '1000.50'];
        yield 'no-break space' => ["1\u{a0}000,50", 'cs', '1000.50'];
        yield 'thin space' => ["1\u{2009}000,50", 'cs', '1000.50'];
        yield 'narrow space' => ["12\u{202f}345\u{202f}678", 'cs', '12345678'];
        yield 'a point in Czech' => ['1000.5', 'cs', '1000.5'];
        yield 'English in Czech' => ['1,000.50', 'cs', '1000.50'];
        yield 'German in Czech' => ['1.000,50', 'cs', '1000.50'];
        yield 'a comma is a decimal mark in Czech' => ['1,000', 'cs', '1.000'];
        yield 'English' => ['1,000.50', 'en', '1000.50'];
        yield 'a comma groups in English' => ['1,000', 'en', '1000'];
        yield 'a short comma is a decimal mark in English' => ['1,5', 'en', '1.5'];
        yield 'several groups' => ['1,000,000', 'en', '1000000'];
        yield 'negative' => ['-1 000,50', 'cs', '-1000.50'];
        yield 'badly grouped spaces' => ['12 34,5', 'cs', '12 34,5'];
        yield 'badly grouped commas' => ['1,00,000', 'en', '1,00,000'];
        yield 'mixed badly' => ['1.00,5', 'cs', '1.00,5'];
        yield 'a word' => ['abc', 'cs', 'abc'];
    }

    #[DataProvider('numbers')]
    public function testReadsWhatPeopleTypeAndPaste(string $typed, string $locale, string $expected): void
    {
        self::assertSame($expected, TypedNumber::decimal($typed, $locale));
    }
}
