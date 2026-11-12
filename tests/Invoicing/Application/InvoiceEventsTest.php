<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * What issuing, delivering and paying leave in the event log, read from the rows
 * the use cases wrote in their own transaction.
 */
final class InvoiceEventsTest extends SendingTestCase
{
    use ReadsEventLog;

    public function testIssuingRecordsIssued(): void
    {
        $invoice = $this->issue->issue($this->input());

        $events = $this->events('invoicing.invoice.issued');
        self::assertCount(1, $events);
        self::assertSame($invoice->id(), $events[0]['subject']);
        self::assertSame([
            'invoice_id' => $invoice->id(),
            'kind' => 'invoice',
            'number' => $invoice->number(),
            'series_id' => $this->series->id(),
            'firm_id' => $this->firm->id(),
            'customer_id' => $this->customer->id(),
            'currency' => 'CZK',
            'total' => '1210.00',
            'issued_on' => '2026-11-01',
            'due_on' => '2026-11-15',
            'corrects' => null,
        ], $events[0]['data']);
    }

    public function testCorrectionRecordsIssuedWithCorrects(): void
    {
        $original = $this->issue->issue($this->input());

        $credit = $this->correctionOf($original, '-100.00');

        $events = $this->events('invoicing.invoice.issued');
        self::assertCount(2, $events);
        self::assertSame($credit->id(), $events[1]['subject']);
        self::assertSame('correction_document', $events[1]['data']['kind']);
        self::assertSame($original->id(), $events[1]['data']['corrects']);
        self::assertSame('-121.00', $events[1]['data']['total']);
    }

    public function testFirstDeliveryRecordsDeliveredOnce(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        self::mockTime('2026-11-03 10:01:00');

        $this->worker->run();
        $this->delivered->mark($invoice->id());

        $events = $this->events('invoicing.invoice.delivered');
        self::assertCount(1, $events);
        self::assertSame($invoice->id(), $events[0]['subject']);
        self::assertSame('email', $events[0]['data']['channel']);
        self::assertSame($invoice->id(), $events[0]['data']['invoice_id']);
        $at = $events[0]['data']['delivered_at'];
        self::assertIsString($at);
        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:01:00 UTC'), new \DateTimeImmutable($at));
    }

    public function testManualMarkRecordsManual(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->delivered->mark($invoice->id());
        $this->delivered->mark($invoice->id());

        $events = $this->events('invoicing.invoice.delivered');
        self::assertCount(1, $events);
        self::assertSame('manual', $events[0]['data']['channel']);
    }

    public function testPaymentRecordsPaymentRecorded(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());
        $czk = static fn (string $amount): Money => Money::of($amount, Currency::CZK);

        $payment = self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-02'), $czk('100.00'), PaymentMethod::BankTransfer, PaymentSource::Manual, null, 'note', [
            new AllocationRequest($second->id(), $czk('40.00')),
            new AllocationRequest($first->id(), $czk('60.00')),
        ]));

        $events = $this->events('invoicing.payment.recorded');
        self::assertCount(1, $events);
        self::assertSame($payment->id(), $events[0]['subject']);
        $ids = [$first->id(), $second->id()];
        sort($ids);
        self::assertSame([
            'payment_id' => $payment->id(),
            'firm_id' => $this->firm->id(),
            'amount' => '100.00',
            'currency' => 'CZK',
            'received_on' => '2026-11-02',
            'direction' => 'incoming',
            'source' => 'manual',
            'invoice_ids' => $ids,
        ], $events[0]['data']);
        self::assertSame([], $this->events('invoicing.invoice.paid'));
    }
}
