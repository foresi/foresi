<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\CorrectedDocument;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\LineAmounts;
use Foresi\Invoicing\Contract\SummaryLine;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceCalculator;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Shared\Domain\Money;

/**
 * Builds the view of an invoice from what is stored, header and lines from one
 * read: its rate summaries add up the stored amounts of the lines and nothing is
 * priced again, so a document issued under older rules keeps its figures.
 */
final readonly class IssuedDocumentFactory
{
    public function __construct(private InvoiceStore $store, private InvoiceCalculator $calculator)
    {
    }

    /**
     * @param Money|null $owed what is still owed, for a reminder: below the payable, the document asks for it alone
     *
     * @throws UnknownInvoice
     */
    public function of(string $id, ?Money $owed = null): IssuedDocument
    {
        $stored = $this->store->snapshot($id);
        $invoice = $stored->invoice;
        $lines = $stored->lines;
        $summaries = $this->calculator->summaries(
            array_map(static fn (InvoiceLine $line): SummaryLine => new SummaryLine($line->treatment(), $line->rate(), new LineAmounts($line->unitNet(), $line->net(), $line->vat(), $line->gross())), $lines),
            Money::ofMinor(0, $invoice->currency()),
        );

        $corrected = null === $invoice->correctedInvoiceId() ? null : $this->store->get($invoice->correctedInvoiceId());

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
            $invoice->isTrashed(),
            $owed instanceof Money && $owed->minor() < $invoice->payable()->minor() ? $owed : null,
            null === $invoice->advanceInvoiceId() ? null : $this->store->get($invoice->advanceInvoiceId())->number(),
            $invoice->deductsAdvances(),
            $this->deposits($lines),
            $corrected?->number(),
            $invoice->correctionReason(),
            $invoice->decisiveDate(),
            $corrected instanceof Invoice ? new CorrectedDocument($corrected->id(), $corrected->kind(), $corrected->number(), $corrected->issueDate()) : null,
        );
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return array<string, array{number: string, variableSymbol: string, taxed: bool}>
     */
    private function deposits(array $lines): array
    {
        $deposits = [];
        foreach ($lines as $line) {
            $id = $line->deducts();
            if (null !== $id && !isset($deposits[$id])) {
                $deducted = $this->store->get($id);
                $deposits[$id] = ['number' => $deducted->number(), 'variableSymbol' => $deducted->variableSymbol(), 'taxed' => DocumentKind::PaymentTaxDocument === $deducted->kind()];
            }
        }

        return $deposits;
    }
}
