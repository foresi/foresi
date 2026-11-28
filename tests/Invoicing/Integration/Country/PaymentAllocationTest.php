<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;

/**
 * An allocation puts a positive part of a payment on an invoice, all in one currency.
 */
final class PaymentAllocationTest extends InvoiceTestCase
{
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoice = $this->issue->issue($this->input());
    }

    public function testTakesAPartOfThePaymentAndItsSettlement(): void
    {
        $allocation = new PaymentAllocation($this->payment(Currency::of('CZK')), $this->invoice, Money::of('100.00', Currency::of('CZK')), Money::of('0.50', Currency::of('CZK')));

        self::assertSame([10000, 50], [$allocation->amount()->minor(), $allocation->settlement()->minor()]);
    }

    public function testARefundTakesAZeroSettlement(): void
    {
        $allocation = new PaymentAllocation($this->payment(Currency::of('CZK'), PaymentDirection::Refund), $this->invoice, Money::of('100.00', Currency::of('CZK')), Money::of('0.00', Currency::of('CZK')));

        self::assertSame([-10000, 0], [$allocation->amount()->minor(), $allocation->settlement()->minor()]);
    }

    public function testARefundWritesNothingOff(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A refund writes nothing off.'));

        new PaymentAllocation($this->payment(Currency::of('CZK'), PaymentDirection::Refund), $this->invoice, Money::of('100.00', Currency::of('CZK')), Money::of('0.50', Currency::of('CZK')));
    }

    public function testAnInvoiceInAnotherCurrencyThanThePaymentIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::of('EUR')), $this->invoice, Money::of('100.00', Currency::of('EUR')));
    }

    public function testAnAmountInAnotherCurrencyThanThePaymentIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::of('CZK')), $this->invoice, Money::of('100.00', Currency::of('EUR')));
    }

    public function testASettlementInAnotherCurrencyIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::of('CZK')), $this->invoice, Money::of('100.00', Currency::of('CZK')), Money::of('0.50', Currency::of('EUR')));
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation takes a positive part of the payment.'));

        new PaymentAllocation($this->payment(Currency::of('CZK')), $this->invoice, Money::of('-1.00', Currency::of('CZK')));
    }

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation takes a positive part of the payment.'));

        new PaymentAllocation($this->payment(Currency::of('CZK')), $this->invoice, Money::of('0.00', Currency::of('CZK')));
    }

    private function payment(Currency $currency, PaymentDirection $direction = PaymentDirection::Incoming): Payment
    {
        return new Payment(new \DateTimeImmutable('2026-11-20'), Money::of('100.00', $currency), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', null, new \DateTimeImmutable('2026-11-20 10:00'), $direction);
    }
}
