<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use Psr\Clock\ClockInterface;

/**
 * Moves a received document to the trash with a reason, as an invoice goes
 * (ADR 0013): it keeps its evidence number, its content and its file, takes no
 * change until RestoreReceivedDocument brings it back, and the audit log keeps
 * who did it, when and why.
 */
final readonly class TrashReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private DocumentLock $lock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a document of another tenant
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

            return $document;
        });
    }
}
