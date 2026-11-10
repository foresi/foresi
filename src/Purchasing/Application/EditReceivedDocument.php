<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;

/**
 * Changes a received document outside the trash. A draft takes what is given,
 * complete or not; a recorded document must stay complete and keeps its
 * evidence number. Once the month close exists, a document of a closed month
 * is locked (DocumentLock).
 */
final readonly class EditReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private ReceivedDocumentContents $contents,
        private DocumentRecording $recording,
        private DocumentLock $lock,
        private DocumentWarnings $warnings,
    ) {
    }

    /**
     * @param int|null $version the version the person saw; another one is refused with STALE
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a document of another tenant
     * @throws ReceivedDocumentRefused with TRASHED in the trash, LOCKED once locked, and for anything the document refuses
     */
    public function edit(string $documentId, ReceivedDocumentInput $input, ?int $version = null): SavedReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();
        $document = $this->unit->run(function () use ($documentId, $input, $version, $userId): ReceivedDocument {
            $document = $this->documents->lock($documentId);
            $firm = $this->access->activeFirm($document->firm(), true);
            if (null !== $version && $version !== $document->version()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::STALE);
            }
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            if ($this->lock->isLocked($document)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LOCKED);
            }
            if ($document->isRecorded()) {
                $this->recording->record($document, $firm, $this->contents->of($firm, $input, $document, true), $userId);
            } else {
                $document->saveDraft($this->contents->of($firm, $input, $document, false));
            }

            return $document;
        });

        return new SavedReceivedDocument($document, $this->warnings->of($document));
    }
}
