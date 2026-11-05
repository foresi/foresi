<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Quantity;

/**
 * What the form of a corrective tax document opens with: the invoice it corrects
 * with every line negated, a credit note of all of it to edit down. Firm, customer,
 * payment, texts and the rates and treatments of the lines stay; the default series
 * of corrections numbers it. It writes nothing: only Save issues.
 */
final readonly class CorrectInvoice
{
    public function __construct(private NewInvoiceDefaults $defaults, private InvoiceStore $store, private CorrectPaymentTaxDocument $taxDocuments)
    {
    }

    /**
     * Whether a corrective tax document can be issued for a document.
     */
    public static function corrects(Invoice $source): bool
    {
        return CorrectionRules::correctable($source);
    }

    /**
     * @throws \DomainException   for a document that is no issued invoice in place
     * @throws UnsupportedCountry for a firm whose country has no invoicing rules
     */
    public function valuesFrom(Invoice $source): InvoiceDraftValues
    {
        if (!self::corrects($source)) {
            throw new \DomainException('Only an invoice that is not in the trash, or a delivered tax document on payment, is corrected.');
        }
        $draft = $this->defaults->for($source->firm(), kind: DocumentKind::CorrectionDocument);
        if (DocumentKind::PaymentTaxDocument === $source->kind()) {
            return $this->ofTaxDocument($draft, $source);
        }

        return $draft->with([
            'customerId' => $source->customerParty()->id(),
            'paymentMethod' => $source->paymentMethod(),
            'bankAccount' => $source->bankAccount() ?? $draft->bankAccount,
            'description' => $source->description(),
            'language' => $source->language(),
            'lines' => array_values(array_map($this->negated(...), array_filter($this->store->lines($source), static fn (InvoiceLine $line): bool => null === $line->deducts()))),
            'correctedInvoiceId' => $source->id(),
        ]);
    }

    /**
     * A tax document on payment opens with what is due on it, dated on the day of the
     * fact, at the rates of the receipt; the reason is the controller's to fill in.
     */
    private function ofTaxDocument(InvoiceDraftValues $draft, Invoice $source): InvoiceDraftValues
    {
        $due = $this->taxDocuments->due($source);
        $values = [
            'customerId' => $source->customerParty()->id(),
            'paymentMethod' => $source->paymentMethod(),
            'bankAccount' => $source->bankAccount() ?? $draft->bankAccount,
            'variableSymbol' => $source->variableSymbol(),
            'description' => $source->description(),
            'language' => $source->language(),
            'lines' => $this->taxDocuments->linesFor($source),
            'correctedInvoiceId' => $source->id(),
        ];
        if ($due instanceof CorrectionDue) {
            $values += ['issueDate' => $due->decisiveDate, 'taxPointDate' => $due->decisiveDate, 'dueDate' => $due->decisiveDate];
        }

        return $draft->with($values);
    }

    private function negated(InvoiceLine $line): LineInput
    {
        $quantity = Quantity::ofTenThousandths(-$line->quantity()->tenThousandths());

        return new LineInput($line->text(), $quantity, $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount());
    }
}
