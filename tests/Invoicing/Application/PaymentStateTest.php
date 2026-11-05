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

    /**
     * An invoice of 1 210 with a credit note of -484 or a debit note of +242.
     *
     * @return iterable<string, array{int, int, PaymentState}>
     */
    public static function corrected(): iterable
    {
        yield 'credit, nothing paid' => [-48400, 0, PaymentState::Unpaid];
        yield 'credit, part paid' => [-48400, 50000, PaymentState::PartlyPaid];
        yield 'credit, the rest paid' => [-48400, 72600, PaymentState::Paid];
        yield 'credit after a payment in full' => [-48400, 121000, PaymentState::ToRefund];
        yield 'credited in full, nothing paid' => [-121000, 0, PaymentState::Paid];
        yield 'credited in full after a part payment' => [-121000, 100, PaymentState::ToRefund];
        yield 'debit after a payment in full' => [24200, 121000, PaymentState::PartlyPaid];
        yield 'debit paid too' => [24200, 145200, PaymentState::Paid];
        yield 'no correction, overpaid' => [0, 121001, PaymentState::Overpaid];
    }

    #[DataProvider('corrected')]
    public function testCorrectionsChangeWhatIsDue(int $corrections, int $paid, PaymentState $state): void
    {
        self::assertSame($state, PaymentState::of(Money::ofMinor(121000, Currency::CZK), Money::ofMinor($paid, Currency::CZK), Money::ofMinor($corrections, Currency::CZK)));
    }

    public function testACorrectionInAnotherCurrencyIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);
        PaymentState::of(Money::ofMinor(100, Currency::CZK), Money::ofMinor(0, Currency::CZK), Money::ofMinor(-100, Currency::EUR));
    }

    public function testOnlyPaidOverpaidAndToRefundAreSettled(): void
    {
        self::assertSame(
            ['unpaid' => false, 'partly_paid' => false, 'paid' => true, 'overpaid' => true, 'to_refund' => true],
            array_combine(array_map(static fn (PaymentState $state): string => $state->value, PaymentState::cases()), array_map(static fn (PaymentState $state): bool => $state->isSettled(), PaymentState::cases())),
        );
    }

    public function testAnotherCurrencyIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);
        PaymentState::of(Money::ofMinor(100, Currency::CZK), Money::ofMinor(100, Currency::EUR));
    }
}
