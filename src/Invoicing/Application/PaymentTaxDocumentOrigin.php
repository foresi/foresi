<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * A payment received on an advance invoice, as what issues its tax document. It
 * names the advance and the payment on the document in the transaction that stores
 * it; their pair is unique, so a second document for the payment never commits.
 */
final readonly class PaymentTaxDocumentOrigin implements IssueOrigin
{
    public function __construct(public string $advanceId, public string $paymentId, private AdvanceDocuments $documents)
    {
    }

    public function withNumber(string $text, string $number): string
    {
        return $text;
    }

    /**
     * @throws IssueRefused when the payment got its document meanwhile: a retry after losing to it
     */
    public function recordIn(Invoice $invoice): void
    {
        if (null !== $this->documents->taxDocumentOf($this->advanceId, $this->paymentId)) {
            throw new IssueRefused([new IssueProblem('document', 'invoice.problem.tax_document_exists')]);
        }
        $invoice->taxesPayment($this->advanceId, $this->paymentId);
    }
}
