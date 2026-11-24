<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Reading\DocumentReadings;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * One received document for its page, the API and MCP: the document with its
 * original file, what a credit note corrects or could correct, its history
 * and the reading of its file.
 * Only the owners and members of the organisation read it, in the trash too.
 */
final readonly class ReceivedDocumentDetails
{
    /** The documents offered to a credit note at most, newest first. */
    public const int CORRECTABLE = 200;

    public function __construct(
        private PurchasingAccess $access,
        private ReceivedDocuments $documents,
        private ReceivedDocumentQueries $queries,
        private Attachments $attachments,
        private ReceivedDocumentHistory $history,
        private DocumentReadings $readings,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     */
    public function of(string $documentId): ReceivedDocumentDetail
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($documentId) ?? throw new UnknownReceivedDocument();
        $fileId = $document->originalAttachmentId();
        $file = null === $fileId ? null : $this->attachments->get($fileId);
        $correctable = $this->queries->correctable($document->id(), self::CORRECTABLE);
        $originalId = $document->originalDocumentId();
        // The original may be older than the documents offered, or in the trash since, so it is read on its own.
        $corrected = null === $originalId ? null : $this->row($originalId);

        return new ReceivedDocumentDetail(
            $document,
            $file instanceof Attachment ? $file : null,
            $corrected,
            $correctable,
            $this->history->of($document),
            $this->readings->find($document->id()),
        );
    }

    /**
     * The document and the reading of its file alone, for a page that waits for the reading.
     *
     * @return array{ReceivedDocument, ?DocumentReading}
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     */
    public function reading(string $documentId): array
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($documentId) ?? throw new UnknownReceivedDocument();

        return [$document, $this->readings->find($document->id())];
    }

    /**
     * The recorded documents a new credit note may correct.
     *
     * @return list<ReceivedDocumentRow> newest first
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function correctable(): array
    {
        $this->access->denyUnlessStaff();

        return $this->queries->correctable(null, self::CORRECTABLE);
    }

    private function row(string $id): ?ReceivedDocumentRow
    {
        $document = $this->documents->find($id);
        if (!$document instanceof ReceivedDocument) {
            return null;
        }

        return new ReceivedDocumentRow(
            $document->id(),
            $document->state(),
            $document->kind(),
            $document->treatment(),
            $document->evidenceNumber(),
            $document->supplier()->name,
            $document->supplierNumber(),
            $document->issueDate(),
            $document->taxPointDate(),
            $document->receivedDate(),
            $document->total(),
            $document->vatCzk(),
            $document->createdAt(),
            $document->deletedAt(),
            $document->deletedBy(),
            $document->deletedReason(),
            $document->supplierParty()?->id(),
        );
    }
}
