<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Domain;

use App\ExchangeRate\Application\Quote;
use App\ExchangeRate\Domain\ExchangeRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExchangeRateTest extends TestCase
{
    public function testARateIsADecimalStringWithItsAmount(): void
    {
        $day = new \DateTimeImmutable('2026-11-10');
        $rate = new ExchangeRate('JPY', 100, '13.717', $day, $day);

        self::assertSame(100, $rate->amount);
        self::assertSame('13.717', $rate->rate);
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(int $amount, string $rate): void
    {
        $day = new \DateTimeImmutable('2026-11-10');
        $this->expectException(\InvalidArgumentException::class);

        new ExchangeRate('EUR', $amount, $rate, $day, $day);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no amount' => [0, '24.335'];
        yield 'a comma' => [1, '24,335'];
        yield 'an exponent' => [1, '2.4e1'];
        yield 'a negative rate' => [1, '-24.335'];
    }

    public function testTrailingZerosOfAQuoteAreDropped(): void
    {
        self::assertSame('24.3', new Quote('EUR', 1, '24.300')->rate);
        self::assertSame('25', new Quote('EUR', 1, '25.000')->rate);
        self::assertSame('100', new Quote('EUR', 1, '100')->rate);
    }
}
