<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Attachment\Application\Attachments;
use App\Attachment\Domain\Attachment;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;

/**
 * One received document for its page, the API and MCP: the document with its
 * original file, what a credit note corrects or could correct, and its history.
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
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a document of another tenant
     */
    public function of(string $documentId): ReceivedDocumentDetail
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($documentId) ?? throw new UnknownReceivedDocument();
        $fileId = $document->originalAttachmentId();
        $file = null === $fileId ? null : $this->attachments->get($fileId);
        $correctable = $this->queries->correctable($document->firm()->id(), $document->id(), self::CORRECTABLE);
        $originalId = $document->originalDocumentId();
        // The original may be older than the documents offered, or in the trash since, so it is read on its own.
        $corrected = null === $originalId ? null : $this->row($originalId);

        return new ReceivedDocumentDetail(
            $document,
            $file instanceof Attachment ? $file : null,
            $corrected,
            $correctable,
            $this->history->of($document),
        );
    }

    /**
     * The recorded documents of the firm a new credit note may correct.
     *
     * @return list<ReceivedDocumentRow> newest first
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function correctable(string $firmId): array
    {
        $this->access->denyUnlessStaff();

        return $this->queries->correctable($firmId, null, self::CORRECTABLE);
    }

    private function row(string $id): ?ReceivedDocumentRow
    {
        $document = $this->documents->find($id);
        if (!$document instanceof ReceivedDocument) {
            return null;
        }

        return new ReceivedDocumentRow(
            $document->id(),
            $document->firm()->id(),
            $document->firm()->name(),
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
        );
    }
}
