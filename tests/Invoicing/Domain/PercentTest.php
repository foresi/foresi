<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\Percent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentTest extends TestCase
{
    public function testReadsAWholePercent(): void
    {
        $percent = Percent::fromString('21');

        self::assertSame(2100, $percent->basisPoints());
        self::assertSame('21.00', $percent->decimal());
        self::assertSame('0.21', $percent->factorOf());
        self::assertSame('1.21', $percent->inclusiveFactor());
        self::assertSame('0.79', $percent->remainingFactor());
    }

    public function testReadsTwoDecimals(): void
    {
        $percent = Percent::fromString('12.5');

        self::assertSame(1250, $percent->basisPoints());
        self::assertSame('12.50', $percent->decimal());
        self::assertSame('0.125', $percent->factorOf());
        self::assertSame('1.125', $percent->inclusiveFactor());
        self::assertSame('0.875', $percent->remainingFactor());
        self::assertSame('0.0575', Percent::fromString('5.75')->factorOf());
    }

    public function testTheLimitsAreAllowed(): void
    {
        self::assertSame('0', Percent::fromString('0')->factorOf());
        self::assertSame('0.00', Percent::fromString('0.00')->decimal());
        self::assertSame('1', Percent::fromString('100')->factorOf());
        self::assertSame('0', Percent::fromString('100')->remainingFactor());
        self::assertSame('1', Percent::fromString('0')->remainingFactor());
        self::assertSame('2', Percent::fromString('100')->inclusiveFactor());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'above 100' => ['100.01'];
        yield 'far above' => ['101'];
        yield 'empty' => [''];
        yield 'text' => ['abc'];
        yield 'three decimals' => ['1.234'];
        yield 'comma' => ['21,5'];
        yield 'sign' => ['+21'];
        yield 'space' => [' 21'];
        yield 'trailing newline' => ["21\n"];
        yield 'bare dot' => ['21.'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotAPercentage(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Percent::fromString($value);
    }

    public function testEquality(): void
    {
        self::assertTrue(Percent::fromString('21')->equals(Percent::fromString('21.00')));
        self::assertFalse(Percent::fromString('21')->equals(Percent::fromString('12')));
    }

    public function testAWholeHundredKeepsAllItsValues(): void
    {
        $hundred = Percent::fromString('100');

        self::assertSame('100.00', $hundred->decimal());
        self::assertSame('1', $hundred->factorOf());
        self::assertSame('2', $hundred->inclusiveFactor());
    }
}
