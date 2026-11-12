<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;

/**
 * An invoice is paid once, when a payment, or the cancelling of one, moves it
 * from outside paid and overpaid into them.
 */
final class PaymentTransitionsTest extends AdvanceTestCase
{
    use ReadsEventLog;

    public function testPartPaymentIsNotPaid(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->pay($invoice, '500.00');

        self::assertSame([], $this->events('invoicing.invoice.paid'));
    }

    public function testCompletingPaymentRecordsPaid(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice, '500.00');

        $payment = $this->pay($invoice, '710.00');

        self::assertSame([['subject' => $invoice->id(), 'data' => ['invoice_id' => $invoice->id(), 'state' => 'paid', 'payment_id' => $payment->id()]]], $this->events('invoicing.invoice.paid'));
    }

    public function testOverpaymentOfAnUnpaidInvoiceRecordsOverpaid(): void
    {
        $invoice = $this->issue->issue($this->input());

        $payment = $this->pay($invoice, '1300.00');

        self::assertSame([['subject' => $invoice->id(), 'data' => ['invoice_id' => $invoice->id(), 'state' => 'overpaid', 'payment_id' => $payment->id()]]], $this->events('invoicing.invoice.paid'));
    }

    public function testOverpaymentOfPaidInvoiceRecordsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice, '1210.00');

        $this->pay($invoice, '90.00');

        self::assertCount(1, $this->events('invoicing.invoice.paid'));
    }

    public function testCancellingPaymentRecordsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $payment = $this->pay($invoice, '1210.00');

        $this->cancel->cancel($payment->id());

        self::assertCount(1, $this->events('invoicing.invoice.paid'));
    }

    public function testCancellingTheOverpayingPaymentRecordsPaidAgain(): void
    {
        $invoice = $this->toRefund();
        $surplus = $this->pay($invoice, '484.00');
        $paidBefore = $this->events('invoicing.invoice.paid');
        self::assertCount(1, $paidBefore, 'The invoice was paid before the surplus came.');

        $this->cancel->cancel($surplus->id());

        $events = $this->events('invoicing.invoice.paid');
        self::assertCount(2, $events);
        self::assertSame(['invoice_id' => $invoice->id(), 'state' => 'paid', 'payment_id' => $surplus->id()], $events[1]['data']);
    }

    public function testRefundingWhatIsToRefundRecordsPaid(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice, '1210.00');
        $this->correctionOf($invoice, '-400.00');

        $refund = $this->refund($invoice, '484.00');

        $events = $this->events('invoicing.invoice.paid');
        self::assertCount(2, $events);
        self::assertSame(['invoice_id' => $invoice->id(), 'state' => 'paid', 'payment_id' => $refund->id()], $events[1]['data']);
    }

    public function testCancellingRefundRecordsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice, '1210.00');
        $this->correctionOf($invoice, '-400.00');
        $refund = $this->refund($invoice, '484.00');

        $this->cancel->cancel($refund->id());

        self::assertCount(2, $this->events('invoicing.invoice.paid'));
    }

    public function testTaxDocumentOnPaymentIsNeverPaid(): void
    {
        $advance = $this->advance([$this->gross('1210.00')]);

        $this->pay($advance, '1210.00');

        $document = $this->onlyTaxDocumentOf($advance);
        $subjects = array_column($this->events('invoicing.invoice.paid'), 'subject');
        self::assertSame([$advance->id()], $subjects);
        self::assertNotContains($document->id(), $subjects);
        self::assertCount(2, $this->events('invoicing.invoice.issued'));
    }

    /**
     * Paid in full, then credited by 400 without VAT: 484 is to refund. The invoice
     * is paid in the eyes of the events until the surplus is paid in by mistake.
     */
    private function toRefund(): Invoice
    {
        $invoice = $this->issue->issue($this->input());
        $this->correctionOf($invoice, '-400.00');
        $this->pay($invoice, '726.00');

        return $invoice;
    }

    private function refund(Invoice $invoice, string $amount): Payment
    {
        $money = $this->czk($amount);

        return $this->record->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $money)], PaymentDirection::Refund));
    }
}
