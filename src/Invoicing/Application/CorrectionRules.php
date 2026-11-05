<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\LegalEntity;

/**
 * What a corrective tax document must be, on top of what every document must be.
 * It corrects an issued invoice in place, or a delivered tax document on payment
 * that no final invoice deducts, of the same firm and customer, at the rates of
 * that document (§42(7)(a); for a tax document on payment those of the receipt,
 * §42(7)(b)): its tax point is the decisive date of a change
 * (§42) or the tax point of the invoice for an error (§43). Per rate and treatment,
 * the invoice and all its corrections never go below zero. It reads and never writes.
 */
final readonly class CorrectionRules
{
    public function __construct(private InvoiceStore $store, private LinePricing $pricing)
    {
    }

    /**
     * The day the rates of a correction are taken on: that of the invoice it corrects.
     * Null when the document is no correction, or its invoice is unknown.
     */
    public function rateDate(InvoiceInput $input, ?Invoice $existing = null): ?\DateTimeImmutable
    {
        if (DocumentKind::CorrectionDocument !== ($existing?->kind() ?? $input->kind)) {
            return null;
        }

        return $this->original($input)?->rateDate();
    }

    /**
     * @param Invoice|null $existing the correction being edited
     *
     * @return list<IssueProblem>
     */
    public function check(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing, VatRegime $regime, InvoicingCountry $country): array
    {
        $correction = $input->correction;
        if (DocumentKind::CorrectionDocument !== ($existing?->kind() ?? $input->kind)) {
            return $correction instanceof CorrectionInput ? [new IssueProblem('document', 'invoice.problem.correction_kind')] : [];
        }
        if (!$correction instanceof CorrectionInput || '' === $correction->correctedInvoiceId) {
            return [new IssueProblem('correctedInvoiceId', 'invoice.problem.correction_link_missing')];
        }
        if ($existing instanceof Invoice && $existing->correctedInvoiceId() !== $correction->correctedInvoiceId) {
            return [new IssueProblem('correctedInvoiceId', 'invoice.problem.correction_link_locked')];
        }
        $original = $this->original($input);
        if (!$original instanceof Invoice || !self::correctable($original)) {
            return [new IssueProblem('correctedInvoiceId', 'invoice.problem.correction_original_invalid')];
        }
        // A deduction on a final invoice holds the tax document until it is released.
        if (DocumentKind::PaymentTaxDocument === $original->kind() && null !== $original->deductedByInvoiceId()) {
            return [new IssueProblem('correctedInvoiceId', 'invoice.problem.correction_deducted')];
        }
        if (DocumentKind::PaymentTaxDocument === $original->kind() && CorrectionType::Error === $correction->type) {
            return [new IssueProblem('correctionType', 'invoice.problem.correction_payment_change')];
        }
        $problems = [...$this->texts($correction), ...$this->taxPoint($input, $correction, $original)];
        if ($original->firm()->id() !== $firm->id()) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.correction_firm');
        }
        if ($original->customerParty()->id() !== $input->customerId) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.correction_customer');
        }

        return [...$problems, ...$this->lines($input, $original, $existing, $regime, $country)];
    }

    /**
     * Writes the correction onto its document, on an issue and on an edit alike.
     */
    public static function record(Invoice $invoice, CorrectionInput $correction): void
    {
        $type = $correction->type ?? throw new \LogicException('CorrectionRules refuses a missing type.');
        $date = $correction->decisiveDate ?? throw new \LogicException('CorrectionRules refuses a missing decisive date.');
        $invoice->corrects($correction->correctedInvoiceId, trim($correction->reason), $type, $date);
    }

    /**
     * What a correction may correct: an invoice in place, or a tax document on
     * payment the customer got.
     */
    public static function correctable(Invoice $original): bool
    {
        return (DocumentKind::Invoice === $original->kind() && !$original->isTrashed()) || CorrectPaymentTaxDocument::corrects($original);
    }

    private function original(InvoiceInput $input): ?Invoice
    {
        $id = $input->correction->correctedInvoiceId ?? '';
        if ('' === $id) {
            return null;
        }
        try {
            return $this->store->get($id);
        } catch (UnknownInvoice) {
            return null;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function texts(CorrectionInput $correction): array
    {
        $problems = [];
        if ('' === trim($correction->reason)) {
            $problems[] = new IssueProblem('correctionReason', 'invoice.problem.correction_reason_missing');
        } elseif (mb_strlen(trim($correction->reason)) > Invoice::MAX_CORRECTION_REASON_LENGTH) {
            $problems[] = new IssueProblem('correctionReason', 'invoice.problem.too_long');
        }
        if (!$correction->type instanceof CorrectionType) {
            $problems[] = new IssueProblem('correctionType', 'invoice.problem.correction_type_missing');
        }
        if (!$correction->decisiveDate instanceof \DateTimeImmutable) {
            $problems[] = new IssueProblem('decisiveDate', 'invoice.problem.correction_decisive_date_missing');
        }

        return $problems;
    }

    /**
     * @return list<IssueProblem>
     */
    private function taxPoint(InvoiceInput $input, CorrectionInput $correction, Invoice $original): array
    {
        $expected = match ($correction->type) {
            CorrectionType::Change => $correction->decisiveDate,
            CorrectionType::Error => $original->taxPointDate(),
            null => null,
        };
        if (!$expected instanceof \DateTimeImmutable || !$input->taxPointDate instanceof \DateTimeImmutable) {
            return [];
        }

        return $expected->format('Y-m-d') === $input->taxPointDate->format('Y-m-d') ? [] : [new IssueProblem('taxPointDate', 'invoice.problem.correction_tax_point')];
    }

    /**
     * Each line keeps a rate and treatment of the invoice, and no rate and treatment
     * goes below zero across the invoice and its corrections.
     *
     * @return list<IssueProblem>
     */
    private function lines(InvoiceInput $input, Invoice $original, ?Invoice $existing, VatRegime $regime, InvoicingCountry $country): array
    {
        $left = [];
        foreach ($this->store->lines($original) as $line) {
            $key = $this->key($line->treatment(), $line->rateKind());
            $left[$key] = ($left[$key] ?? 0) + $line->net()->minor();
        }
        $problems = [];
        foreach ($input->lines as $index => $line) {
            if (!\array_key_exists($this->key($line->treatment, $line->rateKind), $left)) {
                $problems[] = new IssueProblem('lines.'.$index.'.vat', 'invoice.problem.correction_rate_unknown');
            }
        }
        if ([] !== $problems) {
            return $problems;
        }

        return $this->overOriginal($input, $original, $existing, $regime, $country, $left) ? [new IssueProblem('lines', 'invoice.problem.correction_over_original')] : [];
    }

    /**
     * The cap once more, inside the write transaction: the row of the invoice is
     * locked first, so a correction saved meanwhile is counted and two saves of
     * the same invoice's corrections run one after the other.
     *
     * @param Invoice|null $existing the correction being edited
     *
     * @throws IssueRefused when the corrections of the invoice would go below zero
     */
    public function claim(InvoiceInput $input, ?Invoice $existing, VatRegime $regime, InvoicingCountry $country): void
    {
        $id = $input->correction->correctedInvoiceId ?? '';
        if ('' === $id || DocumentKind::CorrectionDocument !== ($existing?->kind() ?? $input->kind)) {
            return;
        }
        $this->store->lockRow($id);
        $original = $this->store->get($id);
        $left = [];
        foreach ($this->store->lines($original) as $line) {
            $key = $this->key($line->treatment(), $line->rateKind());
            $left[$key] = ($left[$key] ?? 0) + $line->net()->minor();
        }
        if ($this->overOriginal($input, $original, $existing, $regime, $country, $left)) {
            throw new IssueRefused([new IssueProblem('lines', 'invoice.problem.correction_over_original')]);
        }
    }

    /**
     * @param array<string, int> $left the net of the invoice per rate and treatment
     */
    private function overOriginal(InvoiceInput $input, Invoice $original, ?Invoice $existing, VatRegime $regime, InvoicingCountry $country, array $left): bool
    {
        foreach ($this->store->correctionsOf($original) as $other) {
            if ($other->id() === $existing?->id()) {
                continue;
            }
            foreach ($this->store->lines($other) as $line) {
                $key = $this->key($line->treatment(), $line->rateKind());
                $left[$key] = ($left[$key] ?? 0) + $line->net()->minor();
            }
        }
        try {
            $priced = $this->pricing->priced($input->lines, $regime, $country, $original->rateDate());
        } catch (\OverflowException|\DomainException) {
            // The amounts of the lines say so on their own.
            return false;
        }
        foreach ($priced as $line) {
            $key = $this->key($line->input->treatment, $line->input->rateKind);
            $left[$key] = ($left[$key] ?? 0) + $line->amounts->net->minor();
        }

        return array_any($left, static fn (int $net): bool => $net < 0);
    }

    private function key(TaxTreatment $treatment, ?VatRateKind $kind): string
    {
        return $treatment->value.'|'.($kind->value ?? '');
    }
}
