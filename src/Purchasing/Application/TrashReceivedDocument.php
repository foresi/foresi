<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Contract\Event\ReceivedDocumentTrashed;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Application\Event\EventRecorder;
use Psr\Clock\ClockInterface;

/**
 * Moves a received document to the trash with a reason, as an invoice goes
 * (ADR 0013): it keeps its evidence number, its content and its file, takes no
 * change until RestoreReceivedDocument brings it back, and the audit log keeps
 * who did it, when and why. Trashing a recorded document records
 * purchasing.received_document.trashed; a draft records no event.
 */
final readonly class TrashReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private DocumentLock $lock,
        private ClockInterface $clock,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with TRASHED when it is there already, LOCKED once locked, or without a usable reason
     */
    public function trash(string $documentId, string $reason): ReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->unit->run(function () use ($documentId, $reason, $userId): ReceivedDocument {
            $document = $this->documents->lock($documentId);
            if ($this->lock->isLocked($document)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LOCKED);
            }
            $document->trash(\DateTimeImmutable::createFromInterface($this->clock->now()), $userId, $reason);
            if ($document->isRecorded()) {
                $this->events->record(new ReceivedDocumentTrashed($document->id()));
            }

            return $document;
        });
    }
}
