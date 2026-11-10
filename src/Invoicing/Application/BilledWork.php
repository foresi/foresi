<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: work another module bills on a new invoice, such as time on projects.
 * That module prefills the invoice form, and its origin records what was
 * billed in the transaction that issues the invoice. The form carries the
 * reference, and every line it prefilled carries its source, so the module
 * finds which line bills what however the lines were edited. Invoicing
 * declares the port and knows nothing of what is behind it.
 */
interface BilledWork
{
    /**
     * What a new invoice opens with; null when the reference no longer holds.
     */
    public function draft(string $reference): ?BilledWorkDraft;

    /**
     * What issues an invoice whose input bills the work it refers to; the input's
     * lines tell by their sources which line bills what.
     *
     * @throws IssueRefused when the reference no longer holds
     */
    public function origin(InvoiceInput $input): IssueOrigin;
}
