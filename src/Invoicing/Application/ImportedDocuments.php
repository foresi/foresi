<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Domain\LegalEntity;

/**
 * The imported documents of the active tenant. Call remove() inside TransactionalWork.
 */
interface ImportedDocuments
{
    public function find(LegalEntity $firm, string $source, string $externalId): ?Invoice;

    /**
     * @return list<Invoice> in the order of their numbers
     */
    public function ofRun(string $runId): array;

    /**
     * What else was recorded against a document: payments allocated to it, sends of
     * it, documents correcting it, taxing a payment of it or deducting it.
     *
     * @return list<string> the reasons, as UndoBlocker names them
     */
    public function dependents(Invoice $invoice): array;

    /**
     * Deletes the document and its lines for good.
     */
    public function remove(Invoice $invoice): void;
}
