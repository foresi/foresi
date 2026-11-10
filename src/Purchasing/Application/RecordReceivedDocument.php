<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Party\Application\UnknownLegalEntity;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentContent;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use Psr\Clock\ClockInterface;

/**
 * Records a received document: a draft completed with the values given, or a
 * new one typed in directly. It takes the next evidence number of the firm's
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
     * @throws UnknownReceivedDocument also for a document of another tenant
     * @throws ReceivedDocumentRefused with RECORDED once recorded, and for anything the document refuses
     */
    public function record(string $documentId, ReceivedDocumentInput $input, ?int $version = null): SavedReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();
        $document = $this->unit->run(function () use ($documentId, $input, $version, $userId): ReceivedDocument {
            // The document first, then its firm: both are writes, so the reads after them never wait for a write.
            $document = $this->documents->lock($documentId);
            $firm = $this->access->activeFirm($document->firm(), true);
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
            $this->recording->record($document, $firm, $this->contents->of($firm, $input, $document, true), $userId);

            return $document;
        });

        return new SavedReceivedDocument($document, $this->warnings->of($document));
    }

    /**
     * A new document for the firm, recorded at once, without a file.
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownLegalEntity      also for a firm of another tenant
     * @throws ReceivedDocumentRefused with FIRM_ARCHIVED for an archived firm, and for anything the document refuses
     */
    public function create(string $firmId, ReceivedDocumentInput $input): SavedReceivedDocument
    {
        $userId = $this->access->denyUnlessStaff();
        $document = $this->unit->run(function () use ($firmId, $input, $userId): ReceivedDocument {
            // The lock of the firm is the first write, as EvidenceNumbers asks.
            $firm = $this->access->activeFirm($firmId, true);
            $content = $this->contents->of($firm, $input, null, true);
            $document = new ReceivedDocument($firm, ReceivedDocumentContent::blank(), $userId, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->recording->record($document, $firm, $content, $userId);
            $this->documents->add($document);

            return $document;
        });

        return new SavedReceivedDocument($document, $this->warnings->of($document));
    }
}
