<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Party\Application\NoFirmYet;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Psr\Clock\ClockInterface;

/**
 * Records a received document: a draft completed with the values given, or a
 * new one typed in directly. It takes the next evidence number of the
 * series, the snapshot of the supplier, which becomes a supplier of the firm,
 * and the published exchange rate of the tax point when the document is in
 * another currency and no rate was given. A recorded document is changed with
 * EditReceivedDocument.
 */
final readonly class RecordReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private ReceivedDocumentContents $contents,
        private DocumentRecording $recording,
        private DocumentLock $lock,
        private DocumentWarnings $warnings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Completes a draft and records it.
     *
     * @param int|null $version the version the person saw; another one is refused with STALE
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with RECORDED once recorded, and for anything the document refuses
     */
    public function record(string $documentId, ReceivedDocumentInput $input, ?int $version = null): SavedReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();
        $document = $this->unit->run(function () use ($documentId, $input, $version, $userId): ReceivedDocument {
            // The document first, then the firm: both are writes, so the reads after them never wait for a write.
            $document = $this->documents->lock($documentId);
            $this->access->firm(true);
            if (null !== $version && $version !== $document->version()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::STALE);
            }
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            if ($document->isRecorded()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::RECORDED);
            }
            if ($this->lock->isLocked($document)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LOCKED);
            }
            $this->recording->record($document, $this->contents->of($input, $document, true), $userId);

            return $document;
        });

        return new SavedReceivedDocument($document, $this->warnings->of($document));
    }

    /**
     * A new document, recorded at once, without a file.
     *
     * @param DocumentSource $source how it came in: typed in, or through the API
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws NoFirmYet
     * @throws ReceivedDocumentRefused for anything the document refuses
     */
    public function create(ReceivedDocumentInput $input, DocumentSource $source = DocumentSource::Manual): SavedReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();
        $document = $this->unit->run(function () use ($input, $userId, $source): ReceivedDocument {
            // The lock of the firm is the first write, as EvidenceNumbers asks.
            $this->access->firm(true);
            $content = $this->contents->of($input, null, true);
            $document = new ReceivedDocument(ReceivedDocumentContent::blank(), $userId, \DateTimeImmutable::createFromInterface($this->clock->now()), $source);
            // Added before it is recorded, so a listener of the recording event finds it.
            $this->documents->add($document);
            $this->recording->record($document, $content, $userId);

            return $document;
        });

        return new SavedReceivedDocument($document, $this->warnings->of($document));
    }
}
