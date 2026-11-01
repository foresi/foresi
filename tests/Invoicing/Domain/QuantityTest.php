<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testKeepsFourDecimalsAsTenThousandths(): void
    {
        $quantity = Quantity::fromString('1.5');

        self::assertSame(15000, $quantity->tenThousandths());
        self::assertSame('1.5000', $quantity->toDecimal());
        self::assertSame('0.0001', Quantity::fromString('0.0001')->toDecimal());
        self::assertSame('12.0000', Quantity::fromString('12')->toDecimal());
        self::assertSame(Quantity::ofTenThousandths(5000)->toDecimal(), Quantity::fromString('0.5')->toDecimal());
    }

    public function testZeroAndNegative(): void
    {
        self::assertTrue(Quantity::fromString('0')->isZero());
        self::assertFalse(Quantity::fromString('0.0001')->isZero());
        self::assertSame('-2.5000', Quantity::fromString('-2.5')->toDecimal());
        self::assertSame('-0.0500', Quantity::fromString('-0.05')->toDecimal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['abc'];
        yield 'five decimals' => ['1.00001'];
        yield 'comma' => ['1,5'];
        yield 'space' => [' 1'];
        yield 'trailing newline' => ["1\n"];
        yield 'too large' => ['1000000000'];
        yield 'too small' => ['-1000000000'];
        yield 'bare dot' => ['1.'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotAQuantity(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Quantity::fromString($value);
    }

    public function testTheLargestQuantityIsAllowed(): void
    {
        self::assertSame('999999999.9999', Quantity::fromString('999999999.9999')->toDecimal());
    }

    public function testTheBoundIsInclusive(): void
    {
        self::assertSame(9_999_999_999_999, Quantity::ofTenThousandths(9_999_999_999_999)->tenThousandths());

        $this->expectException(\InvalidArgumentException::class);
        Quantity::ofTenThousandths(10_000_000_000_000);
    }

    public function testZeroHasNoSign(): void
    {
        self::assertSame('0.0000', Quantity::fromString('0')->toDecimal());
    }
}
