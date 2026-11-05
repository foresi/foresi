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
    public function __construct(public string $advanceId, public string $paymentId)
    {
    }

    public function withNumber(string $text, string $number): string
    {
        return $text;
    }

    public function recordIn(Invoice $invoice): void
    {
        $invoice->taxesPayment($this->advanceId, $this->paymentId);
    }
}
