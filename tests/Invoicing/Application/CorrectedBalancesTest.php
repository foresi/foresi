<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * What the lists make of an invoice of 1 210 and its corrections: what is left to
 * pay is the payable plus the corrections not in the trash less what was paid.
 */
final class CorrectedBalancesTest extends InvoiceTestCase
{
    public function testTheInvoiceTabsFollowTheCorrections(): void
    {
        $credited = $this->issue->issue($this->input());
        $this->correctionOf($credited, '-1000.00');
        $refund = $this->issue->issue($this->input());
        $this->pay($refund->id(), '1210.00');
        $this->correctionOf($refund, '-400.00');
        $debited = $this->issue->issue($this->input());
        $this->pay($debited->id(), '1210.00');
        $this->correctionOf($debited, '200.00');
        $this->em->clear();

        self::assertEqualsCanonicalizing([$credited->number(), $refund->number()], $this->numbers(InvoiceFilter::PAID));
        self::assertSame([$debited->number()], $this->numbers(InvoiceFilter::UNPAID));
        $states = $this->states(DocumentKind::Invoice);
        self::assertSame(PaymentState::Paid, $states[$credited->number()], 'credited in full, it is settled');
        self::assertSame(PaymentState::ToRefund, $states[$refund->number()]);
        self::assertSame(PaymentState::PartlyPaid, $states[$debited->number()]);
        self::assertSame(-48400, $this->payments()->remainingOf($this->store->get($refund->id()))->minor(), 'below zero by what is to refund');
    }

    public function testTheCorrectionListHoldsTheCreditNotesToRefund(): void
    {
        $refund = $this->issue->issue($this->input());
        $this->pay($refund->id(), '1210.00');
        $toRefund = $this->correctionOf($refund, '-400.00');
        $open = $this->issue->issue($this->input());
        $lowering = $this->correctionOf($open, '-400.00');
        $this->correctionOf($this->issue->issue($this->input()), '200.00');
        $this->em->clear();

        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::TO_REFUND, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::CorrectionDocument));
        self::assertSame([$toRefund->number()], $this->numbers(InvoiceFilter::TO_REFUND, DocumentKind::CorrectionDocument));
        $page = $this->store->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: DocumentKind::CorrectionDocument));
        self::assertSame([InvoiceFilter::ALL => 3, InvoiceFilter::TO_REFUND => 1, InvoiceFilter::TRASH => 0], $page->tabCounts);
        foreach ($page->rows as $row) {
            self::assertFalse($row->overdue, 'a correction is never overdue');
        }
        self::assertContains($lowering->number(), array_map(static fn (InvoiceRow $row): string => $row->number, $page->rows));
    }

    /**
     * @return list<string>
     */
    private function numbers(string $tab, DocumentKind $kind = DocumentKind::Invoice): array
    {
        return array_map(static fn (InvoiceRow $row): string => $row->number, $this->store->page(new InvoiceFilter(null, $tab, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind))->rows);
    }

    /**
     * @return array<string, PaymentState>
     */
    private function states(DocumentKind $kind): array
    {
        $rows = $this->store->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind))->rows;

        return array_combine(array_map(static fn (InvoiceRow $row): string => $row->number, $rows), array_map(static fn (InvoiceRow $row): PaymentState => $row->payment, $rows));
    }

    private function payments(): InvoicePayments
    {
        return self::getContainer()->get(InvoicePayments::class);
    }

    private function pay(string $invoiceId, string $amount): void
    {
        $czk = Money::of($amount, Currency::CZK);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoiceId, $czk)]));
        $this->em->clear();
    }
}
