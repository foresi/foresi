<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\Candidate;
use App\Banking\Application\Matcher;
use App\Banking\Application\PairingChoices;
use App\Banking\Application\PairingRefused;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\CorrectionInput;
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

/**
 * Outgoing money pairs as a refund: to an invoice of 1 210 paid in full and
 * credited by 484, found by its own symbol or by its credit note's.
 */
final class RefundPairingTest extends BankingTestCase
{
    public function testAnOutgoingMovementWithTheInvoicesSymbolRecordsARefund(): void
    {
        [$invoice] = $this->toRefund();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());

        $candidates = $this->matcher->candidates($this->storedMovement($movement->id()));
        self::assertSame([[$invoice->id(), 48400]], array_map(static fn (Candidate $candidate): array => [$candidate->invoiceId, $candidate->remaining->minor()], $candidates));
        $options = self::getContainer()->get(PairingChoices::class)->of($this->storedMovement($movement->id()));
        self::assertSame([$invoice->id()], array_keys($options->prefill));

        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('484.00'))]);
        $this->em->clear();

        self::assertSame(PaymentDirection::Refund, $this->payments->get($payment->id())->direction());
        self::assertSame(MovementState::Paired, $this->storedMovement($movement->id())->state());
        self::assertSame(0, $this->payments->toRefundOf($this->store->get($invoice->id()))->minor());
    }

    public function testTheCreditNotesSymbolFindsItsInvoice(): void
    {
        [$invoice, $credit] = $this->toRefund();
        self::assertMatchesRegularExpression('/^7\d{8}$/', $credit->variableSymbol(), 'the symbol of the credit note follows its number');
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $credit->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());

        $candidates = $this->matcher->candidates($this->storedMovement($movement->id()));

        self::assertSame([$invoice->id()], array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $candidates));
    }

    public function testCancellingThePairedRefundRecordsTheMovementOnlyAgain(): void
    {
        [$invoice] = $this->toRefund();
        $movement = $this->recordMovement->record($this->incoming('484.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());
        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('484.00'))]);

        self::getContainer()->get(CancelPayment::class)->cancel($payment->id());
        $this->em->clear();

        self::assertSame(MovementState::Recorded, $this->storedMovement($movement->id())->state());
        self::assertSame(48400, $this->payments->toRefundOf($this->store->get($invoice->id()))->minor());
    }

    public function testAnUnrelatedOutgoingMovementIsStillRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00', vs: $invoice->variableSymbol(), direction: MovementDirection::Outgoing), self::verified());

        self::assertSame([], self::getContainer()->get(Matcher::class)->candidates($this->storedMovement($movement->id())));
        try {
            $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]);
            self::fail('The outgoing movement paid an invoice.');
        } catch (PairingRefused $refused) {
            self::assertSame(PairingRefused::NOT_INCOMING, $refused->reason);
        }
        self::assertSame(0, $this->paymentCount());
    }

    /**
     * @return array{Invoice, Invoice} the invoice and its credit note
     */
    private function toRefund(): array
    {
        $invoice = $this->issue->issue($this->input());
        $czk = $this->czk('1210.00');
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $czk)]));
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
