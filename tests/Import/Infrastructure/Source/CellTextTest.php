<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Infrastructure\Source\CellText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CellTextTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null, ''];
        yield 'string' => [' text ', ' text '];
        yield 'true' => [true, '1'];
        yield 'false' => [false, '0'];
        yield 'int' => [1234567, '1234567'];
        yield 'whole float' => [1234567.0, '1234567'];
        yield 'fraction' => [0.5, '0.5'];
        yield 'negative fraction' => [-2.5, '-2.5'];
        yield 'small' => [0.000012, '0.000012'];
        yield 'huge' => [1.0E+25, '10000000000000000000000000'];
        yield 'large with fraction digits' => [1.5E+3, '1500'];
        yield 'exponent with fraction' => [1.25E+2, '125'];
        yield 'negative exponent keeps leading digits' => [-1.5E-7, '-0.00000015'];
        yield 'zero' => [0.0, '0'];
        yield 'date' => [new \DateTimeImmutable('2026-10-01'), '2026-10-01'];
        yield 'date with time' => [new \DateTimeImmutable('2026-10-01 13:45:10'), '2026-10-01 13:45:10'];
        yield 'duration' => [new \DateInterval('PT13H45M10S'), '13:45:10'];
        yield 'not a number' => [NAN, 'NAN'];
        yield 'object' => [new \stdClass(), ''];
    }

    #[DataProvider('values')]
    public function testOf(mixed $value, string $expected): void
    {
        self::assertSame($expected, CellText::of($value));
    }
}
