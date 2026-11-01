<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceCalculator;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\LineAmounts;
use App\Invoicing\Domain\SummaryLine;
use App\Shared\Domain\Money;

/**
 * Builds the view of an invoice from what is stored: its rate summaries add up the
 * stored amounts of the lines and nothing is priced again, so a document issued
 * under older rules keeps its figures.
 */
final readonly class IssuedDocumentFactory
{
    public function __construct(private InvoiceStore $store, private InvoiceCalculator $calculator)
    {
    }

    public function of(Invoice $invoice): IssuedDocument
    {
        $lines = $this->store->lines($invoice);
        $summaries = $this->calculator->summaries(
            array_map(static fn (InvoiceLine $line): SummaryLine => new SummaryLine($line->treatment(), $line->rate(), new LineAmounts($line->unitNet(), $line->net(), $line->vat(), $line->gross())), $lines),
            Money::ofMinor(0, $invoice->currency()),
        );

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
            $summaries,
            $invoice->net(),
            $invoice->vat(),
            $invoice->gross(),
            $invoice->totalRounding(),
            $invoice->payable(),
        );
    }
}
