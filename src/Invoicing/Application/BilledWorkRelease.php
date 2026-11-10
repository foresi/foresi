<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * Told when an invoice goes to the trash and when it comes back, and when the
 * lines of an issued invoice are edited, inside the same transaction, so the
 * module whose work the invoice billed frees that work, bills it again or keeps
 * it with its line. Invoicing declares the port and knows nothing of what is
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

    /**
     * The lines of an issued invoice were edited. A line keeps its position, so
     * a row moved or removed in the form leaves its content on another line:
     * each line id of the map is where a row was, and its value the line the
     * row is on now, or null once the row is gone. The work billed on a row
     * goes with it, and the work of a row removed is free again.
     *
     * @param Invoice                $invoice saved already
     * @param array<string, ?string> $lines   by the id of the line a row was on, the id of the line it is on now, or null
     */
    public function revised(Invoice $invoice, array $lines): void;
}
