<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Domain;

use Foresi\Margin\Domain\Percent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentTest extends TestCase
{
    #[DataProvider('texts')]
    public function testHundredthsAreReadFromTheDigits(string $text, ?int $expected): void
    {
        self::assertSame($expected, Percent::hundredths($text));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function texts(): iterable
    {
        yield '33.33' => ['33.33', 3333];
        yield '33.3' => ['33.3', 3330];
        yield '0.07' => ['0.07', 7];
        yield '100' => ['100', 10_000];
        yield '100.00' => ['100.00', 10_000];
        yield '5' => ['5', 500];
        yield 'three decimals' => ['33.333', null];
        yield 'above 100' => ['100.01', null];
        yield 'empty' => ['', null];
        yield 'sign' => ['-5', null];
        yield 'comma' => ['5,5', null];
    }
}
