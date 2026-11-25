<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Contract\Event\ReceivedDocumentChanged;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentLineInput;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * Sets the lines of a draft or a recorded document on their own: they split
 * the bases of its VAT recap as it stands, and an empty list removes them.
 * Never in the trash or once locked. Lines that change a recorded document
 * record purchasing.received_document.changed with what "lines"; the same
 * lines again change nothing.
 */
final readonly class SetReceivedDocumentLines
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
     * @param list<ReceivedDocumentLineInput> $lines
     * @param int|null                        $version the version the person saw; another one is refused with STALE
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with TRASHED in the trash, LOCKED once locked, and for lines that do not fit the recap
     */
    public function set(string $documentId, array $lines, ?int $version = null): ReceivedDocument
    {
        $this->access->denyUnlessStaff();

        return $this->unit->run(function () use ($documentId, $lines, $version): ReceivedDocument {
            $document = $this->documents->lock($documentId);
            if (null !== $version && $version !== $document->version()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::STALE);
            }
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            if ($this->lock->isLocked($document)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LOCKED);
            }
            if ($document->setLines($lines) && $document->isRecorded()) {
                $this->events->record(new ReceivedDocumentChanged($document->id(), ReceivedDocumentChanged::LINES));
            }

            return $document;
        });
    }
}
