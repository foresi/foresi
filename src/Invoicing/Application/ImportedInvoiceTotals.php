<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What the imported documents of the active tenant came to as Foresi stores
 * them, for comparing an import with its source.
 */
interface ImportedInvoiceTotals
{
    /**
     * @return list<StoredInvoiceTotal> the documents the run brought
     */
    public function ofRun(string $runId): array;

    /**
     * @param list<string> $invoiceIds
     *
     * @return list<StoredInvoiceTotal> those of them that are stored
     */
    public function of(array $invoiceIds): array;
}
