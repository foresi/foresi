<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;

/**
 * Where an invoice comes from, when something other than a person's form issues
 * it. IssueInvoice asks it inside its write transaction, so what it records commits
 * with the invoice or not at all. A retry of the issue asks it again, so it keeps
 * no state of its own between calls.
 */
interface IssueOrigin
{
    /**
     * A text of the document as it reads once its number is known: the number is
     * given inside the transaction, so a text naming it is finished there.
     */
    public function withNumber(string $text, string $number): string;

    /**
     * Called once the invoice, its number and its lines are in the session and
     * before they are written. Throwing aborts the issue: nothing is stored and no
     * number is used up.
     *
     * @param list<InvoiceLine> $lines the lines of the invoice, in the order of the lines of its input
     */
    public function recordIn(Invoice $invoice, array $lines): void;
}
