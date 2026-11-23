<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Contract\Event\ReceivedDocumentRestored;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * Brings a received document back from the trash as it was, with its number.
 * Restoring one that is not in the trash changes nothing. Restoring a recorded
 * document records purchasing.received_document.restored; a draft records no event.
 */
final readonly class RestoreReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private DocumentLock $lock,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
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
            $trashed = $document->isTrashed();
            $document->restore();
            if ($trashed && $document->isRecorded()) {
                $this->events->record(new ReceivedDocumentRestored($document->id()));
            }

            return $document;
        });
    }
}
