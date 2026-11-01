<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @param list<int> $ratios
     * @param list<int> $expectedParts
     */
    #[DataProvider('allocations')]
    public function testAllocationPartsSumToTotal(string $amount, array $ratios, array $expectedParts, int $expectedTotal): void
    {
        $money = Money::of($amount, Currency::CZK);

        $parts = $money->allocate(...$ratios);

        self::assertCount(\count($ratios), $parts);
        self::assertSame($expectedParts, array_map(static fn (Money $part): int => $part->minor(), $parts));
        self::assertSame($expectedTotal, array_sum(array_map(static fn (Money $part): int => $part->minor(), $parts)));
        self::assertSame($expectedTotal, $money->minor());
        foreach ($parts as $part) {
            self::assertSame(Currency::CZK, $part->currency());
        }
    }

    /**
     * @return iterable<string, array{string, list<int>, list<int>, int}>
     */
    public static function allocations(): iterable
    {
        yield '70/20/10 of 1000.01' => ['1000.01', [70, 20, 10], [70001, 20000, 10000], 100001];
        yield '50/50 of 0.01' => ['0.01', [50, 50], [1, 0], 1];
        yield '20/50/30 of 99999.99' => ['99999.99', [20, 50, 30], [2000000, 5000000, 2999999], 9999999];
    }

    public function testAllocationOfNegativeAmountKeepsSignAndTotal(): void
    {
        $parts = Money::of('-0.05', Currency::EUR)->allocate(1, 1, 1);

        self::assertSame([-2, -2, -1], array_map(static fn (Money $part): int => $part->minor(), $parts));
    }

    public function testAllocateRejectsEmptyRatios(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', Currency::CZK)->allocate();
    }

    public function testAllocateRejectsNegativeRatio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', Currency::CZK)->allocate(1, -1);
    }

    public function testAllocateRejectsAllZeroRatios(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', Currency::CZK)->allocate(0, 0);
    }

    /**
     * @param list<int> $ratios
     * @param list<int> $expected
     */
    #[DataProvider('zeroRatioAllocations')]
    public function testZeroRatioPartNeverReceivesMoney(int $minor, array $ratios, array $expected): void
    {
        $parts = Money::ofMinor($minor, Currency::CZK)->allocate(...$ratios);

        self::assertSame($expected, array_map(static fn (Money $part): int => $part->minor(), $parts));
        self::assertSame($minor, array_sum($expected));
    }

    /**
     * @return iterable<string, array{int, list<int>, list<int>}>
     */
    public static function zeroRatioAllocations(): iterable
    {
        yield '1 over 0/1/1' => [1, [0, 1, 1], [0, 1, 0]];
        yield '-1 over 0/1/1' => [-1, [0, 1, 1], [0, -1, 0]];
        yield '5 over 1/0/1' => [5, [1, 0, 1], [3, 0, 2]];
    }

    public function testAllocateRejectsNegativeRatioAmongZeros(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', Currency::CZK)->allocate(0, -1, 2);
    }

    public function testOfRejectsInvalidNumber(): void
    {
        try {
            Money::of('abc', Currency::CZK);
            self::fail('Expected exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertInstanceOf(\Throwable::class, $exception->getPrevious());
        }
    }

    public function testMultipliedByRejectsInvalidNumber(): void
    {
        try {
            Money::of('1', Currency::CZK)->multipliedBy('x');
            self::fail('Expected exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertInstanceOf(\Throwable::class, $exception->getPrevious());
        }
    }

    public function testOfOverflowThrowsOverflowException(): void
    {
        $this->expectException(\OverflowException::class);

        Money::of('92233720368547758.08', Currency::CZK);
    }

    public function testOfAcceptsLargestSupportedAmount(): void
    {
        self::assertSame(\PHP_INT_MAX, Money::of('92233720368547758.07', Currency::CZK)->minor());
    }

    public function testPlusOverflowThrowsOverflowException(): void
    {
        $this->expectException(\OverflowException::class);

        Money::ofMinor(\PHP_INT_MAX, Currency::CZK)->plus(Money::ofMinor(1, Currency::CZK));
    }

    public function testMinusOverflowThrowsOverflowException(): void
    {
        $this->expectException(\OverflowException::class);

        Money::ofMinor(\PHP_INT_MIN, Currency::CZK)->minus(Money::ofMinor(1, Currency::CZK));
    }

    public function testMultipliedByOverflowThrowsOverflowException(): void
    {
        $this->expectException(\OverflowException::class);

        Money::ofMinor(\PHP_INT_MAX, Currency::CZK)->multipliedBy('2');
    }

    public function testPlusRejectsCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('1', Currency::CZK)->plus(Money::of('1', Currency::EUR));
    }

    public function testMinusRejectsCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('1', Currency::USD)->minus(Money::of('1', Currency::EUR));
    }

    public function testPlusAndMinus(): void
    {
        $a = Money::of('10.10', Currency::CZK);
        $b = Money::of('0.25', Currency::CZK);

        self::assertSame(1035, $a->plus($b)->minor());
        self::assertSame(985, $a->minus($b)->minor());
        self::assertSame(-985, $b->minus($a)->minor());
        self::assertSame(Currency::CZK, $a->plus($b)->currency());
        self::assertSame(1010, $a->minor());
    }

    public function testOfRoundsHalfUp(): void
    {
        self::assertSame(1001, Money::of('10.005', Currency::CZK)->minor());
        self::assertSame(1000, Money::of('10.004', Currency::CZK)->minor());
        self::assertSame(-1001, Money::of('-10.005', Currency::CZK)->minor());
    }

    public function testOfMinorAndAccessors(): void
    {
        $money = Money::ofMinor(12345, Currency::EUR);

        self::assertSame(12345, $money->minor());
        self::assertSame(Currency::EUR, $money->currency());
        self::assertTrue($money->equals(Money::of('123.45', Currency::EUR)));
    }

    public function testMultipliedByVatRate(): void
    {
        self::assertSame(25200, Money::of('1200.00', Currency::CZK)->multipliedBy('0.21')->minor());
    }

    public function testMultipliedByRoundsHalfUp(): void
    {
        self::assertSame(1, Money::of('0.05', Currency::CZK)->multipliedBy('0.1')->minor());
        self::assertSame(-1, Money::of('-0.05', Currency::CZK)->multipliedBy('0.1')->minor());
    }

    public function testZeroAndNegative(): void
    {
        self::assertTrue(Money::ofMinor(0, Currency::CZK)->isZero());
        self::assertFalse(Money::ofMinor(1, Currency::CZK)->isZero());
        self::assertFalse(Money::ofMinor(0, Currency::CZK)->isNegative());
        self::assertFalse(Money::ofMinor(1, Currency::CZK)->isNegative());
        self::assertTrue(Money::ofMinor(-1, Currency::CZK)->isNegative());
    }

    public function testEquals(): void
    {
        $money = Money::of('5', Currency::CZK);

        self::assertTrue($money->equals(Money::ofMinor(500, Currency::CZK)));
        self::assertFalse($money->equals(Money::ofMinor(501, Currency::CZK)));
        self::assertFalse($money->equals(Money::ofMinor(500, Currency::EUR)));
    }

    public function testFormatCzechLocale(): void
    {
        self::assertSame("1\u{a0}234,50\u{a0}Kč", Money::of('1234.5', Currency::CZK)->format('cs_CZ'));
    }

    public function testFormatEnglishLocale(): void
    {
        self::assertSame('€1,234.50', Money::of('1234.5', Currency::EUR)->format('en_US'));
    }

    public function testCurrencyMinorUnits(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertSame(2, $currency->minorUnits());
        }
    }

    public function testDividedByRoundsHalfUp(): void
    {
        self::assertSame(10000, Money::of('121.00', Currency::CZK)->dividedBy('1.21')->minor());
        self::assertSame(1, Money::ofMinor(1, Currency::CZK)->dividedBy('2')->minor());
        self::assertSame(0, Money::ofMinor(1, Currency::CZK)->dividedBy('3')->minor());
        self::assertSame(Currency::EUR, Money::ofMinor(100, Currency::EUR)->dividedBy('3')->currency());
    }

    public function testDividedByZeroIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1.00', Currency::CZK)->dividedBy('0');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wholeUnits(): iterable
    {
        yield 'up' => ['193.60', '194.00'];
        yield 'large' => ['122007.93', '122008.00'];
        yield 'half goes up' => ['100.50', '101.00'];
        yield 'below half goes down' => ['100.45', '100.00'];
        yield 'whole stays' => ['100.00', '100.00'];
        yield 'zero' => ['0.00', '0.00'];
        yield 'negative half goes away from zero' => ['-100.50', '-101.00'];
        yield 'negative down' => ['-100.45', '-100.00'];
    }

    #[DataProvider('wholeUnits')]
    public function testRoundedToWholeUnitIsHalfUp(string $amount, string $expected): void
    {
        $rounded = Money::of($amount, Currency::CZK)->roundedToWholeUnit();

        self::assertSame(Money::of($expected, Currency::CZK)->minor(), $rounded->minor());
        self::assertSame(Currency::CZK, $rounded->currency());
    }
}
