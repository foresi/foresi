<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceCalculator;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\LineValues;

/**
 * Builds the view of an invoice. The stored lines hold the figures the document
 * was saved with, so adding them up again gives its rate summaries.
 */
final readonly class IssuedDocumentFactory
{
    public function __construct(private InvoiceStore $store, private InvoiceCalculator $calculator)
    {
    }

    public function of(Invoice $invoice): IssuedDocument
    {
        $lines = $this->store->lines($invoice);
        $totals = $this->calculator->totals(array_map(static fn (InvoiceLine $line): LineValues => $line->values(), $lines), $invoice->vatRegime(), $invoice->rounding(), $invoice->currency());

        return new IssuedDocument(
            $invoice->id(),
            $invoice->kind(),
            $invoice->number(),
            $invoice->vatRegime(),
            $invoice->rounding(),
            $invoice->currency(),
            $invoice->exchangeRate(),
            $invoice->customer(),
            $invoice->issuer(),
            $invoice->issueDate(),
            $invoice->taxPointDate(),
            $invoice->dueDate(),
            $invoice->paymentMethod(),
            $invoice->bankAccount(),
            $invoice->variableSymbol(),
            $invoice->constantSymbol(),
            $invoice->orderNumber(),
            $invoice->description(),
            $invoice->textBefore(),
            $invoice->textAfter(),
            $invoice->language(),
            $lines,
            $totals->summaries,
            $invoice->net(),
            $invoice->vat(),
            $invoice->gross(),
            $invoice->totalRounding(),
            $invoice->payable(),
        );
    }
}
