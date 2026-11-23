<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceHistoryEntry;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\Query\InvoicingReadTestCase;

final class InvoiceDetailsTest extends InvoicingReadTestCase
{
    public function testAnInvoiceComesWithItsLinesPaymentsBalancesCorrectionsAndHistory(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line(text: 'First'), $this->line('500.00', text: 'Second')]));
        $this->pay($invoice->id(), '500.00');
        $credit = $this->correctionOf($invoice, '-100.00');
        $this->em->clear();

        $detail = $this->details()->of($invoice->id());

        self::assertSame($invoice->id(), $detail->invoice->id());
        self::assertSame(['First', 'Second'], array_map(static fn (InvoiceLine $line): string => $line->text(), $detail->lines));
        self::assertCount(1, $detail->payments);
        self::assertSame(50000, $detail->payments[0]->allocated->minor());
        self::assertSame([$credit->id()], array_map(static fn (Invoice $document): string => $document->id(), $detail->corrective));
        self::assertSame(50000, $detail->paid->minor());
        self::assertSame(-12100, $detail->corrections->minor());
        self::assertSame(181500 - 12100 - 50000, $detail->remaining->minor());
        self::assertSame(0, $detail->toRefund->minor());
        self::assertSame(InvoiceHistoryEntry::CREATED, $detail->history[0]->event);
        self::assertContains(InvoiceHistoryEntry::PAID, array_map(static fn (InvoiceHistoryEntry $entry): string => $entry->event, $detail->history));
    }

    public function testAnOverpaymentIsToRefund(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice->id(), '1500.00');

        $detail = $this->details()->of($invoice->id());

        self::assertSame(-29000, $detail->remaining->minor());
        self::assertSame(29000, $detail->toRefund->minor());
    }

    public function testAnInvoiceInTheTrashIsReadToo(): void
    {
        $invoice = $this->issued();
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $this->signIn($this->member);

        $detail = $this->details()->of($invoice->id());

        self::assertTrue($detail->invoice->isTrashed());
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAGuestAndNobodyAreRefused(): void
    {
        $invoice = $this->issued();
        foreach ([fn () => $this->signIn($this->guest), $this->signOut(...)] as $becomes) {
            $becomes();
            try {
                $this->details()->of($invoice->id());
                self::fail('The read was allowed.');
            } catch (InvoicingNotAllowed) {
            }
        }
    }

    private function details(): InvoiceDetails
    {
        return self::getContainer()->get(InvoiceDetails::class);
    }

    private function pay(string $invoiceId, string $amount): void
    {
        $money = Money::of($amount, Currency::CZK);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-20'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoiceId, $money)]));
    }
}
