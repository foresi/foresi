<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * Told when an invoice goes to the trash and when it comes back, inside the same
 * transaction, so the module whose work the invoice billed frees that work and
 * bills it again. Invoicing declares the port and knows nothing of what is
 * behind it.
 */
interface BilledWorkRelease
{
    /**
     * @param Invoice $invoice in the trash already
     */
    public function released(Invoice $invoice): void;

    /**
     * The work released bills on the invoice again, exactly as it did; work
     * billed elsewhere or changed meanwhile refuses the restore.
     *
     * @param Invoice $invoice restored already
     *
     * @throws IssueRefused when some of the work was billed elsewhere, changed or deleted since
     */
    public function reclaim(Invoice $invoice): void;
}
