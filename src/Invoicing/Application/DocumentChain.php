<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;

/**
 * The links of a document to the others of its advance, as iDoklad's "Links"
 * dialog shows them: the advance invoice, its payments, the tax document of each,
 * and the invoices that deduct them. From any of these documents the same chain
 * shows; an invoice deducting several advances shows a chain for each.
 */
final readonly class DocumentChain
{
    public function __construct(private InvoiceStore $store, private InvoicePayments $payments, private AdvanceDocuments $documents)
    {
    }

    /**
     * @return list<array{advance: array{id: string, number: string}, payments: list<array{receivedOn: \DateTimeImmutable, amount: \App\Shared\Domain\Money, taxDocument: array{id: string, number: string}|null}>, finals: list<array{id: string, number: string}>}>
     */
    public function of(Invoice $document): array
    {
        $chains = [];
        foreach ($this->advancesOf($document) as $advance) {
            $finals = [];
            $payments = [];
            if (null !== $advance->deductedByInvoiceId()) {
                $finals[$advance->deductedByInvoiceId()] = true;
            }
            foreach ($this->payments->linesOf($advance) as $line) {
                $taxId = $this->documents->taxDocumentOf($advance->id(), $line->paymentId);
                $tax = null === $taxId ? null : $this->store->get($taxId);
                if (null !== $tax?->deductedByInvoiceId()) {
                    $finals[$tax->deductedByInvoiceId()] = true;
                }
                $payments[] = ['receivedOn' => $line->receivedOn, 'amount' => $line->allocated, 'taxDocument' => $tax instanceof Invoice ? ['id' => $tax->id(), 'number' => $tax->number()] : null];
            }
            $chains[] = [
                'advance' => ['id' => $advance->id(), 'number' => $advance->number()],
                'payments' => $payments,
                'finals' => array_map(fn (string $id): array => ['id' => $id, 'number' => $this->store->get($id)->number()], array_keys($finals)),
            ];
        }

        return $chains;
    }

    /**
     * @return list<Invoice>
     */
    private function advancesOf(Invoice $document): array
    {
        if (DocumentKind::Advance === $document->kind()) {
            return [$document];
        }
        if (null !== $document->advanceInvoiceId()) {
            return [$this->store->get($document->advanceInvoiceId())];
        }
        $advances = [];
        foreach ($this->store->lines($document) as $line) {
            $deducted = $this->deducted($line);
            if ($deducted instanceof Invoice) {
                $advances[$deducted->id()] = $deducted;
            }
        }

        return array_values($advances);
    }

    private function deducted(InvoiceLine $line): ?Invoice
    {
        if (null === $line->deducts()) {
            return null;
        }
        $deducted = $this->store->get($line->deducts());

        return null === $deducted->advanceInvoiceId() ? $deducted : $this->store->get($deducted->advanceInvoiceId());
    }
}
