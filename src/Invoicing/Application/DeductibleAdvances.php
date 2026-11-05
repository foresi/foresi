<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Money;

/**
 * What a final invoice to a customer may deduct, as iDoklad's "Add advance
 * deduction" offers it: each tax document on a payment of the customer's advances
 * (the unit of deduction is a taxed payment, so a part-paid advance deducts what
 * was paid), and each paid advance on which no VAT arose, for what was paid on it.
 * Each deducts with one negative line per rate of it, priced with VAT, so the VAT
 * recap of the invoice is net of the advances (§37a).
 */
final readonly class DeductibleAdvances
{
    public function __construct(
        private AdvanceDocuments $documents,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private AdvanceTaxation $taxation,
        private PaymentTaxDocumentLines $split,
        private PaymentTaxDocumentTexts $texts,
    ) {
    }

    /**
     * @param Invoice|null $except the invoice being edited: what it deducts stays on offer
     *
     * @return list<Deductible>
     */
    public function for(LegalEntity $firm, string $customerId, ?Invoice $except, DocumentLanguage $language): array
    {
        $ids = $this->documents->deductibleFor($firm->id(), $customerId, $except?->id());
        $deductible = [];
        foreach ($ids['taxDocuments'] as $id) {
            $document = $this->store->get($id);
            $advance = $this->store->get($document->advanceInvoiceId() ?? throw new \LogicException('A tax document names its advance.'));
            $text = $this->texts->deductionText($advance, $document, $language);
            $lines = array_map(static fn (InvoiceLine $line): LineInput => new LineInput($text, $line->quantity(), '', Money::ofMinor(-$line->gross()->minor(), $line->gross()->currency()), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount(), $document->id()), $this->store->lines($document));
            $deductible[] = new Deductible($document->id(), $document->number(), $advance->number(), $document->gross(), $lines);
        }
        foreach ($ids['advances'] as $id) {
            $advance = $this->store->get($id);
            $paid = $this->payments->paidOf($advance);
            if ($this->taxation->of($advance)->arises || $paid->isZero() || $paid->isNegative()) {
                continue;
            }
            $lines = array_map(
                static fn (LineInput $line): LineInput => new LineInput($line->text, $line->quantity, $line->unit, Money::ofMinor(-$line->price->minor(), $line->price->currency()), $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount, $advance->id()),
                $this->split->of($this->store->lines($advance), $paid, $this->texts->deductionText($advance, null, $language)),
            );
            $deductible[] = new Deductible($advance->id(), $advance->number(), $advance->number(), $paid, $lines);
        }

        return $deductible;
    }
}
