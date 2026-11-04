<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\InvoiceTestCase;

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
        $allocation = new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('100.00', Currency::CZK), Money::of('0.50', Currency::CZK));

        self::assertSame([10000, 50], [$allocation->amount()->minor(), $allocation->settlement()->minor()]);
    }

    public function testAnInvoiceInAnotherCurrencyThanThePaymentIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::EUR), $this->invoice, Money::of('100.00', Currency::EUR));
    }

    public function testAnAmountInAnotherCurrencyThanThePaymentIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('100.00', Currency::EUR));
    }

    public function testASettlementInAnotherCurrencyIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.'));

        new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('100.00', Currency::CZK), Money::of('0.50', Currency::EUR));
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation takes a positive part of the payment.'));

        new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('-1.00', Currency::CZK));
    }

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('An allocation takes a positive part of the payment.'));

        new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('0.00', Currency::CZK));
    }

    public function testTheTenantIsKnownOnlyOnceStored(): void
    {
        $allocation = new PaymentAllocation($this->payment(Currency::CZK), $this->invoice, Money::of('100.00', Currency::CZK));

        $this->expectExceptionObject(new \LogicException('The tenant is stamped on persist.'));

        $allocation->tenantId();
    }

    private function payment(Currency $currency): Payment
    {
        return new Payment($this->firm, new \DateTimeImmutable('2026-11-20'), Money::of('100.00', $currency), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', null, new \DateTimeImmutable('2026-11-20 10:00'));
    }
}
