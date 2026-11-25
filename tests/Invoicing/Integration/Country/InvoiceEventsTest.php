<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\BilledWorkRelease;
use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Events\Support\ReadsEventLog;
use Foresi\Tests\Invoicing\Application\SendingTestCase;
use Symfony\Component\Clock\MockClock;

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

        $payment = self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-02'), $czk('100.00'), PaymentMethod::BankTransfer, PaymentSource::Manual, null, 'note', [
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

    public function testTrashRecordsTrashed(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->trash->trash($invoice->id(), 'Wrong customer, Jane Doe');

        $events = $this->events('invoicing.invoice.trashed');
        self::assertCount(1, $events);
        self::assertSame($invoice->id(), $events[0]['subject']);
        self::assertSame(['invoice_id' => $invoice->id(), 'kind' => 'invoice'], $events[0]['data']);
    }

    public function testRestoreRecordsRestored(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->trash->restore($invoice->id());
        $this->trash->restore($invoice->id());

        $events = $this->events('invoicing.invoice.restored');
        self::assertCount(1, $events);
        self::assertSame($invoice->id(), $events[0]['subject']);
        self::assertSame(['invoice_id' => $invoice->id(), 'kind' => 'invoice'], $events[0]['data']);
    }

    public function testRefusedRestoreRecordsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $container = self::getContainer();
        $billed = new class implements BilledWorkRelease {
            public function released(Invoice $invoice): void
            {
            }

            public function reclaim(Invoice $invoice): void
            {
                throw new IssueRefused([new IssueProblem('lines', 'invoice.problem.already_deducted')]);
            }

            public function revised(Invoice $invoice, array $lines): void
            {
            }
        };
        $refusing = new TrashInvoice($this->store, $container->get(InvoiceSends::class), $container->get(InvoicePayments::class), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(\Foresi\Audit\Application\AuditActor::class), new MockClock('2026-11-03'), $this->deductions(), $billed, $container->get(EventRecorder::class));

        try {
            $refusing->restore($invoice->id());
            self::fail('Expected the restore to be refused.');
        } catch (IssueRefused) {
        }

        self::assertSame([], $this->events('invoicing.invoice.restored'));
        self::assertCount(1, $this->events('invoicing.invoice.trashed'));
        self::assertTrue($this->reloaded($invoice)->isTrashed());
    }
}
