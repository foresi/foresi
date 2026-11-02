<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\PaymentState;
use App\Shared\Domain\Currency;
use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentState::class)]
final class PaymentStateTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, PaymentState}>
     */
    public static function amounts(): iterable
    {
        yield 'nothing paid' => [121000, 0, PaymentState::Unpaid];
        yield 'one minor unit paid' => [121000, 1, PaymentState::PartlyPaid];
        yield 'one minor unit short' => [121000, 120999, PaymentState::PartlyPaid];
        yield 'paid exactly' => [121000, 121000, PaymentState::Paid];
        yield 'one minor unit over' => [121000, 121001, PaymentState::Overpaid];
        yield 'a refund taking more than was paid' => [121000, -100, PaymentState::Unpaid];
    }

    #[DataProvider('amounts')]
    public function testTheStateFollowsThePaidAmount(int $payable, int $paid, PaymentState $state): void
    {
        self::assertSame($state, PaymentState::of(Money::ofMinor($payable, Currency::CZK), Money::ofMinor($paid, Currency::CZK)));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function nothingPayable(): iterable
    {
        yield 'zero, nothing paid' => [0, 0];
        yield 'zero, something paid' => [0, 500];
        yield 'negative, nothing paid' => [-121000, 0];
        yield 'negative, the whole amount paid' => [-121000, -121000];
        yield 'negative, something paid' => [-121000, 500];
    }

    #[DataProvider('nothingPayable')]
    public function testZeroPayableShowsNoPaymentState(int $payable, int $paid): void
    {
        self::assertSame(PaymentState::Unpaid, PaymentState::of(Money::ofMinor($payable, Currency::CZK), Money::ofMinor($paid, Currency::CZK)));
    }

    public function testOnlyPaidAndOverpaidAreSettled(): void
    {
        self::assertSame(
            ['unpaid' => false, 'partly_paid' => false, 'paid' => true, 'overpaid' => true],
            array_combine(array_map(static fn (PaymentState $state): string => $state->value, PaymentState::cases()), array_map(static fn (PaymentState $state): bool => $state->isSettled(), PaymentState::cases())),
        );
    }

    public function testAnotherCurrencyIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);
        PaymentState::of(Money::ofMinor(100, Currency::CZK), Money::ofMinor(100, Currency::EUR));
    }
}
