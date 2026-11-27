<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\BilledWorkDraft;

/**
 * Port: work another module bills on a new invoice, such as time on projects,
 * as the invoice form and IssueInvoice ask for it. The modules that bill work
 * implement Foresi\Invoicing\Contract\BilledWork; CollectedBilledWork asks
 * them. Invoicing knows nothing of what is behind it.
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
