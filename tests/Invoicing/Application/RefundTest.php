<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * A refund sends back what is to refund on an invoice of 1 210 paid in full and
 * credited by 400 without VAT, 484 with it: never more, and cancelled like any payment.
 */
final class RefundTest extends InvoiceTestCase
{
    public function testARefundLowersWhatWasPaidAndSettlesTheInvoice(): void
    {
        $invoice = $this->toRefund();
        self::assertSame(48400, $this->payments()->toRefundOf($invoice)->minor());

        $refund = $this->record($invoice, '484.00', PaymentDirection::Refund);

        $invoice = $this->store->get($invoice->id());
        self::assertSame(PaymentDirection::Refund, $this->payments()->get($refund->id())->direction());
        self::assertSame(72600, $this->payments()->paidOf($invoice)->minor(), 'paid is what came less what went back');
        self::assertSame(0, $this->payments()->remainingOf($invoice)->minor());
        self::assertSame(0, $this->payments()->toRefundOf($invoice)->minor());
        self::assertSame(PaymentState::Paid, PaymentState::of($invoice->payable(), $this->payments()->paidOf($invoice), $this->payments()->correctionsOf($invoice)));
        self::assertSame([$invoice->id() => 0], array_map(static fn (Money $owed): int => $owed->minor(), self::getContainer()->get(InvoiceBalances::class)->outstanding([$invoice->id()])));
        self::assertSame(-48400, $this->payments()->linesOf($invoice)[1]->allocated->minor(), 'the panel shows it as money going back');
    }

    public function testAPartRefundLeavesTheRestToRefund(): void
    {
        $invoice = $this->toRefund();

        $this->record($invoice, '84.00', PaymentDirection::Refund);

        self::assertSame(40000, $this->payments()->toRefundOf($this->store->get($invoice->id()))->minor());
    }

    public function testARefundAboveWhatIsToRefundIsRefused(): void
    {
        $invoice = $this->toRefund();

        $this->expectRefused(PaymentRefused::REFUND_TOO_LARGE, fn (): Payment => $this->record($invoice, '484.01', PaymentDirection::Refund));
        self::assertSame(121000, $this->payments()->paidOf($this->store->get($invoice->id()))->minor());
    }

    public function testAPlainOverpaymentRefundsWhatWasPaidAboveTheInvoice(): void
    {
        $overpaid = $this->issue->issue($this->input());
        $this->record($overpaid, '1300.00');

        self::assertSame(9000, $this->payments()->toRefundOf($this->store->get($overpaid->id()))->minor(), 'a plain overpayment is to refund');
        $this->expectRefused(PaymentRefused::REFUND_TOO_LARGE, fn (): Payment => $this->record($overpaid, '90.01', PaymentDirection::Refund));
    }

    public function testAnInvoicePaidExactlyRefusesARefund(): void
    {
        $paid = $this->issue->issue($this->input());
        $this->record($paid, '1210.00');

        self::assertSame(0, $this->payments()->toRefundOf($this->store->get($paid->id()))->minor());
        $this->expectRefused(PaymentRefused::REFUND_TOO_LARGE, fn (): Payment => $this->record($paid, '0.01', PaymentDirection::Refund));
    }

    public function testARefundWritesNothingOff(): void
    {
        $invoice = $this->toRefund();
        $czk = Money::of('484.00', Currency::CZK);

        $this->expectRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $czk, Money::of('0.50', Currency::CZK))], PaymentDirection::Refund)));
    }

    public function testCancellingARefundBringsBackWhatIsToRefund(): void
    {
        $invoice = $this->toRefund();
        $refund = $this->record($invoice, '484.00', PaymentDirection::Refund);

        self::getContainer()->get(CancelPayment::class)->cancel($refund->id());
        $this->em->clear();

        $invoice = $this->store->get($invoice->id());
        self::assertTrue($this->payments()->get($refund->id())->isCancelled());
        self::assertSame(48400, $this->payments()->toRefundOf($invoice)->minor());
        self::assertSame(PaymentState::ToRefund, PaymentState::of($invoice->payable(), $this->payments()->paidOf($invoice), $this->payments()->correctionsOf($invoice)));
    }

    private function toRefund(): Invoice
    {
        $invoice = $this->issue->issue($this->input());
        $this->record($invoice, '1210.00');
        $this->correctionOf($invoice, '-400.00');
        $this->em->clear();

        return $this->store->get($invoice->id());
    }

    private function record(Invoice $invoice, string $amount, PaymentDirection $direction = PaymentDirection::Incoming): Payment
    {
        $czk = Money::of($amount, Currency::CZK);
        $payment = self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $czk)], $direction));
        $this->em->clear();

        return $payment;
    }

    private function expectRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail('The refund was recorded.');
        } catch (PaymentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function payments(): InvoicePayments
    {
        return self::getContainer()->get(InvoicePayments::class);
    }
}
