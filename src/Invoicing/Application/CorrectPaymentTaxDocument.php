<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Quantity;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Corrects a tax document on payment the customer got once its payment is cancelled
 * or money goes back on its advance (§42(1)(c)). The correction negates what is
 * left of the document, split over its rates as the receipt was, at the rates and
 * FX rate of the receipt (§42(7)(b)), as a change dated on the day of the fact.
 *
 * Refunds go back on the latest payments of the advance first. A tax document the
 * customer never got goes to the trash instead, replaced by one for what stays paid. One that a final invoice deducts is
 * not corrected until the deduction is released; it waits, as do those a rule
 * refused, among the uncorrected ones.
 */
final readonly class CorrectPaymentTaxDocument
{
    public function __construct(
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private AdvanceDocuments $documents,
        private PaymentTaxDocumentLines $lines,
        private PaymentTaxDocumentTexts $texts,
        private IssueInvoice $issue,
        private Savepoint $savepoint,
        private ClockInterface $clock,
        private IssuePaymentTaxDocument $taxDocuments,
    ) {
    }

    /**
     * Whether a document is a tax document on payment that a correction may correct.
     */
    public static function corrects(Invoice $document): bool
    {
        return DocumentKind::PaymentTaxDocument === $document->kind() && !$document->isTrashed() && $document->isDelivered();
    }

    /**
     * What is left to correct on a tax document; null when nothing is.
     */
    public function due(Invoice $document): ?CorrectionDue
    {
        if (!self::corrects($document)) {
            return null;
        }
        $paymentId = $document->paymentId();
        $advanceId = $document->advanceInvoiceId();
        if (null === $paymentId || null === $advanceId) {
            return null;
        }
        $left = $this->left($document);
        if ($left <= 0) {
            return null;
        }
        $payment = $this->payments->get($paymentId);
        $cancelledAt = $payment->cancelledAt();
        if ($cancelledAt instanceof \DateTimeImmutable) {
            return new CorrectionDue(Money::ofMinor($left, $document->currency()), false, new \DateTimeImmutable($cancelledAt->format('Y-m-d')));
        }
        [$net, $refundedOn] = $this->netOf($this->store->get($advanceId), $paymentId);
        $owed = min($left, $left - $net);
        if ($owed <= 0 || !$refundedOn instanceof \DateTimeImmutable) {
            return null;
        }

        return new CorrectionDue(Money::ofMinor($owed, $document->currency()), true, $refundedOn);
    }

    /**
     * Inside the transaction that cancels the payment or records the refund, at a
     * savepoint of its own: a refusal stores nothing of the correction, the payment
     * stands, and the tax document waits among the uncorrected ones.
     *
     * @return Invoice|null the correction; null when nothing is due, a final invoice deducts the document, or a rule refused it
     */
    public function automatically(Invoice $document): ?Invoice
    {
        $due = $this->due($document);
        if (!$due instanceof CorrectionDue || null !== $document->deductedByInvoiceId()) {
            return null;
        }
        $input = $this->input($document, $due);
        try {
            return $this->savepoint->run(fn (): Invoice => $this->issue->issue($input));
        } catch (IssueRefused) {
            return null;
        }
    }

    /**
     * Money went back on an advance, in the transaction of the refund and under the
     * lock of the advance: each tax document of its payments the refund reaches goes
     * to the trash while the customer never got it, and is corrected once they did.
     */
    public function afterRefund(string $advanceId): void
    {
        $advance = $this->store->get($advanceId);
        $reached = [];
        foreach (array_reverse($this->payments->linesOf($advance)) as $line) {
            if ($line->allocated->isNegative()) {
                continue;
            }
            $id = $this->documents->taxDocumentOf($advanceId, $line->paymentId);
            if (null !== $id) {
                $reached[] = [$id, $line];
            }
        }
        // All rows are taken before any document changes: a lock refuses a session with unsaved changes.
        foreach ($reached as [$id]) {
            $this->store->lock($id);
        }
        foreach ($reached as [$id, $line]) {
            $document = $this->store->get($id);
            if ($document->isTrashed()) {
                continue;
            }
            if ($document->isDelivered()) {
                $this->automatically($document);
            } else {
                $this->replace($document, $advanceId, $line->paymentId, $line->allocated->minor());
            }
        }
    }

    /**
     * A tax document the customer never got goes to the trash once the refund reaches
     * its payment. When part of the payment stays paid, the document gives the
     * payment up and a new one taxes what stays, on the day of receipt, at a
     * savepoint as on payment; a refusal leaves the payment among those that need a
     * tax document.
     */
    private function replace(Invoice $document, string $advanceId, string $paymentId, int $allocated): void
    {
        $stays = $this->netOf($this->store->get($advanceId), $paymentId)[0];
        if ($stays >= $allocated) {
            return;
        }
        $document->trash(\DateTimeImmutable::createFromInterface($this->clock->now()), null, $this->texts->refundedReason($document));
        if ($stays <= 0) {
            return;
        }
        $document->givesUpPayment();
        $payment = $this->payments->get($paymentId);
        $currency = $document->currency();
        // The savepoint stores the trash first, so the payment is free for its new document.
        $this->savepoint->run(fn (): ?Invoice => $this->taxDocuments->forPayment($payment, $advanceId, Money::ofMinor($stays, $currency)));
    }

    /**
     * What the form of a correction opens with: what is due, or else all that is left.
     *
     * @return list<LineInput>
     */
    public function linesFor(Invoice $document): array
    {
        // Nothing left negates to no lines.
        $amount = $this->due($document)->amount ?? Money::ofMinor($this->left($document), $document->currency());

        return $this->negated($document, $amount);
    }

    public function reasonFor(Invoice $document): string
    {
        $due = $this->due($document);

        return $due instanceof CorrectionDue ? $this->texts->correctionReason($document, $due->refunded) : '';
    }

    private function input(Invoice $document, CorrectionDue $due): InvoiceInput
    {
        $day = $due->decisiveDate;
        $lines = $this->negated($document, $due->amount);

        return new InvoiceInput(
            $document->firm()->id(),
            null,
            null,
            $document->customerParty()->id(),
            $document->customer(),
            $day,
            $day,
            $day,
            $document->paymentMethod(),
            $document->bankAccount(),
            $document->variableSymbol(),
            $document->constantSymbol(),
            $document->orderNumber(),
            $document->description(),
            '',
            '',
            $document->language(),
            $lines,
            kind: DocumentKind::CorrectionDocument,
            correction: new CorrectionInput($document->id(), $this->texts->correctionReason($document, $due->refunded), CorrectionType::Change, $day),
        );
    }

    /**
     * @return list<LineInput>
     */
    private function negated(Invoice $document, Money $amount): array
    {
        $source = $this->store->lines($document);
        if ([] === $source || $amount->minor() <= 0) {
            return [];
        }

        return array_map(
            static fn (LineInput $line): LineInput => new LineInput($line->text, Quantity::ofTenThousandths(-$line->quantity->tenThousandths()), $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount),
            $this->lines->of($source, $amount, $source[0]->text()),
        );
    }

    /**
     * The gross of a tax document after its corrections, in minor units.
     */
    private function left(Invoice $document): int
    {
        $left = $document->gross()->minor();
        foreach ($this->store->correctionsOf($document) as $correction) {
            $left += $correction->gross()->minor();
        }

        return $left;
    }

    /**
     * What stays of a payment of an advance after its refunds, which go back on the
     * latest payments first, and the day of the last refund.
     *
     * @return array{int, \DateTimeImmutable|null}
     */
    private function netOf(Invoice $advance, string $paymentId): array
    {
        $refunded = 0;
        $last = null;
        $incoming = [];
        foreach ($this->payments->linesOf($advance) as $line) {
            if ($line->allocated->isNegative()) {
                $refunded -= $line->allocated->minor();
                // The lines come oldest first, so the last refund is the latest.
                $last = $line->receivedOn;
            } else {
                $incoming[] = $line;
            }
        }
        $last = null === $last ? null : new \DateTimeImmutable($last->format('Y-m-d'));
        foreach (array_reverse($incoming) as $line) {
            $back = min($refunded, $line->allocated->minor());
            $refunded -= $back;
            if ($line->paymentId === $paymentId) {
                return [$line->allocated->minor() - $back, $last];
            }
        }

        return [0, $last];
    }
}
