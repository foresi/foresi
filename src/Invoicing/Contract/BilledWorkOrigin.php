<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * The work an invoice is issued for, as the module that bills it records it.
 * Invoicing calls it inside its write transaction, once the invoice, its number
 * and its lines are in the session and before they are written, so what it
 * records commits with the invoice or not at all. A retry of the issue asks
 * BilledWork again, so it keeps no state of its own between calls.
 */
interface BilledWorkOrigin
{
    /**
     * @param list<string> $lineIds the ids of the lines of the invoice, in the order of the sources it was made with
     *
     * @throws BilledWorkRefused to abort the issue: nothing is stored and no number is used up
     */
    public function recordIn(string $invoiceId, DocumentKind $kind, array $lineIds): void;
}
