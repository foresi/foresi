<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;

/**
 * Brings a received document back from the trash as it was, with its number.
 * Restoring one that is not in the trash changes nothing.
 */
final readonly class RestoreReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private DocumentLock $lock,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a document of another tenant
     * @throws ReceivedDocumentRefused with LOCKED once locked
     */
    public function restore(string $documentId): ReceivedDocument
    {
        $this->access->denyUnlessStaff();

        return $this->unit->run(function () use ($documentId): ReceivedDocument {
            $document = $this->documents->lock($documentId);
            if ($document->isTrashed() && $this->lock->isLocked($document)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LOCKED);
            }
            $document->restore();

            return $document;
        });
    }
}
