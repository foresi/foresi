<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\Candidate;
use App\Banking\Application\PairingChoices;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\PaymentMethod;
use App\Tests\Banking\Support\BankingTestCase;
use App\Tests\Party\Support\FirmAccounts;

/**
 * Outgoing money refunds by itself under the rules incoming money pays by: a
 * verified notice, the symbol of exactly one invoice with something to refund, and
 * exactly what it has to refund. An invoice of 1 210 paid in full and credited by
 * 484, an overpaid invoice and a paid advance no final invoice deducts all qualify.
 */
final class AutoRefundTest extends BankingTestCase
{
    private const string OWN_IBAN = 'DE89370400440532013000';

    public function testAVerifiedRefundOfExactlyWhatIsToRefundPairsByItself(): void
    {
        [$invoice, $credit] = $this->credited();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $credit->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());

        $payment = $this->pairing->auto($movement->id());

        self::assertNotNull($payment);
        $this->em->clear();
        self::assertSame(PaymentDirection::Refund, $this->payments->get($payment->id())->direction());
        self::assertSame(MovementState::Paired, $this->storedMovement($movement->id())->state());
        self::assertSame(0, $this->payments->toRefundOf($this->store->get($invoice->id()))->minor());
    }

    public function testAnotherAmountStaysUnmatchedWithTheInvoiceSuggested(): void
    {
        [$invoice] = $this->credited();
        $movement = $this->recordMovement->record($this->incoming('400.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());

        self::assertNull($this->pairing->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::RefundAmountDiffers], [$stored->state(), $stored->unmatchedReason()]);
        self::assertSame(1, $this->paymentCount(), 'only the payment of the invoice');
        $options = self::getContainer()->get(PairingChoices::class)->of($stored);
        self::assertSame([[$invoice->id(), PairingChoices::BY_SYMBOL]], array_map(static fn ($choice): array => [$choice->candidate->invoiceId, $choice->reason], $options->suggested));
    }

    public function testAnUnverifiedRefundWaitsForAPerson(): void
    {
        [$invoice] = $this->credited();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::unverified());

        self::assertNull($this->pairing->auto($movement->id()));

        self::assertSame(UnmatchedReason::Unverified, $this->storedMovement($movement->id())->unmatchedReason());
        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('484.00'))], checkedInBank: true);
        self::assertSame(PaymentDirection::Refund, $payment->direction(), 'a person still pairs it by hand');
    }

    public function testARefundSentBeforeTheCreditNoteIsOnlySuggested(): void
    {
        [$invoice] = $this->credited();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), bookedOn: '2026-11-19', direction: MovementDirection::Outgoing), self::verified());

        self::assertNull($this->pairing->auto($movement->id()));

        self::assertSame(UnmatchedReason::NoInvoice, $this->storedMovement($movement->id())->unmatchedReason());
    }

    public function testTheSameOutgoingMovementFromAnotherNoticeIsAPossibleDuplicate(): void
    {
        [$invoice] = $this->credited();
        $first = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), messageId: '01963c5e-0000-7000-8000-000000000001', direction: MovementDirection::Outgoing), self::verified());
        $second = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), messageId: '01963c5e-0000-7000-8000-000000000002', direction: MovementDirection::Outgoing), self::verified());

        self::assertNull($this->pairing->auto($second->id()));
        self::assertNotNull($this->pairing->auto($first->id()));

        self::assertSame([MovementState::Paired, MovementState::PossibleDuplicate], [$this->storedMovement($first->id())->state(), $this->storedMovement($second->id())->state()]);
    }

    public function testMoneySentToAnotherAccountOfTheFirmIsAnOwnTransfer(): void
    {
        FirmAccounts::add($this->em, $this->firm, self::OWN_IBAN);
        $this->em->flush();
        [$invoice] = $this->credited();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), counterIban: self::OWN_IBAN, direction: MovementDirection::Outgoing), self::verified());

        self::assertNull($this->pairing->auto($movement->id()));

        self::assertSame([MovementState::OwnTransfer, null], [$this->storedMovement($movement->id())->state(), $this->storedMovement($movement->id())->paymentId()]);
    }

    public function testAnOverpaidInvoiceRefundsWhatWasPaidAboveIt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->paid($invoice, '1300.00');
        self::assertSame(9000, $this->payments->toRefundOf($this->store->get($invoice->id()))->minor());
        self::assertSame([$invoice->number()], $this->toRefundTab(DocumentKind::Invoice));

        $movement = $this->recordMovement->record($this->incoming('90.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());
        self::assertSame([[$invoice->id(), 9000]], array_map(static fn (Candidate $candidate): array => [$candidate->invoiceId, $candidate->remaining->minor()], $this->matcher->candidates($this->storedMovement($movement->id()))));

        self::assertNotNull($this->pairing->auto($movement->id()));
        $this->em->clear();
        self::assertSame(0, $this->payments->toRefundOf($this->store->get($invoice->id()))->minor());
        self::assertSame([], $this->toRefundTab(DocumentKind::Invoice), 'refunded, it leaves the tab');
    }

    public function testAPaidAdvanceNoFinalInvoiceDeductsRefundsAsAWhole(): void
    {
        $advance = $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbol' => '920260001']));
        $paid = $this->recordMovement->record($this->incoming('1210.00', '920260001', bookedOn: '2026-11-19'), self::verified());
        self::assertNotNull($this->pairing->auto($paid->id()));

        $movement = $this->recordMovement->record($this->incoming('1210.00', '920260001', direction: MovementDirection::Outgoing), self::verified());
        $refund = $this->pairing->auto($movement->id());

        self::assertNotNull($refund);
        self::assertSame(PaymentDirection::Refund, $refund->direction());
        $this->em->clear();
        self::assertSame(0, $this->payments->toRefundOf($this->store->get($advance->id()))->minor());
    }

    public function testTheAccountAnInvoiceWasPaidFromSuggestsItsRefund(): void
    {
        $invoice = $this->issue->issue($this->input());
        $paid = $this->recordMovement->record($this->incoming('1300.00', vs: $invoice->variableSymbol(), bookedOn: '2026-11-19'), self::verified());
        self::assertNotNull($this->pairing->auto($paid->id()));
        $other = $this->issue->issue($this->input());
        $this->paid($other, '1300.00');

        $movement = $this->recordMovement->record($this->incoming('50.00', vs: null, direction: MovementDirection::Outgoing), self::verified());
        $options = self::getContainer()->get(PairingChoices::class)->of($this->storedMovement($movement->id()));

        $suggested = array_map(static fn ($choice): array => [$choice->candidate->invoiceId, $choice->reason], $options->suggested);
        self::assertContains([$invoice->id(), PairingChoices::BY_ACCOUNT], $suggested);
        self::assertNotContains([$other->id(), PairingChoices::BY_ACCOUNT], $suggested, 'paid by hand, from no known account');
        self::assertSame([], $options->prefill);
        self::assertSame(UnmatchedReason::NoSymbol, $this->storedMovement($movement->id())->unmatchedReason(), 'only suggested, never paired');
    }

    private function paid(Invoice $invoice, string $amount): void
    {
        $money = $this->czk($amount);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-01'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $money)]));
        $this->em->clear();
    }

    /**
     * @return list<string>
     */
    private function toRefundTab(DocumentKind $kind): array
    {
        return array_map(static fn (InvoiceRow $row): string => $row->number, $this->store->page(new InvoiceFilter(null, InvoiceFilter::TO_REFUND, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind))->rows);
    }

    /**
     * @return array{Invoice, Invoice} the invoice paid in full and its credit note of 484
     */
    private function credited(): array
    {
        $invoice = $this->issue->issue($this->input());
        $this->paid($invoice, '1210.00');
        $line = new LineInput('Work', Quantity::fromString('-1'), 'h', $this->czk('400.00'), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $credit = $this->issue->issue($this->input([$line], [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'variableSymbolFollowsNumber' => true,
            'correction' => new CorrectionInput($invoice->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
        ]));
        $this->em->clear();

        return [$this->store->get($invoice->id()), $this->store->get($credit->id())];
    }
}
