<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Shared\Domain\Money;

/**
 * What a final invoice to a customer may deduct, as iDoklad's "Add advance
 * deduction" offers it: each tax document on a payment of the customer's advances
 * (the unit of deduction is a taxed payment, so a part-paid advance deducts what
 * was paid), and each paid advance on which no VAT arose, for what was paid on it.
 * A tax document deducts with one negative line per rate of it, priced with VAT,
 * so the VAT recap of the invoice is net of the advances (§37a). An advance without
 * VAT deducts with one negative line outside VAT: it lowers what is to pay only.
 * Both go to ISDOC as deposits: taxed and not taxed.
 */
final readonly class DeductibleAdvances
{
    public function __construct(
        private AdvanceDocuments $documents,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private AdvanceTaxation $taxation,
        private PaymentTaxDocumentTexts $texts,
    ) {
    }

    /**
     * @param Invoice|null $except the invoice being edited: what it deducts stays on offer
     *
     * @return list<Deductible>
     */
    public function for(string $customerId, ?Invoice $except, DocumentLanguage $language): array
    {
        $ids = $this->documents->deductibleFor($customerId, $except?->id());
        $deductible = [];
        foreach ($ids['taxDocuments'] as $id) {
            $document = $this->store->get($id);
            $advance = $this->store->get((string) $document->advanceInvoiceId());
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
            // No VAT arose on it, so it lowers what is to pay, not the base: one line outside VAT.
            $line = new LineInput($this->texts->deductionText($advance, null, $language), Quantity::fromString('1'), '', Money::ofMinor(-$paid->minor(), $paid->currency()), PriceType::WithoutVat, TaxTreatment::NotApplicable, null, null, Percent::fromString('0'), $advance->id());
            $deductible[] = new Deductible($advance->id(), $advance->number(), $advance->number(), $paid, [$line]);
        }

        return $deductible;
    }
}
