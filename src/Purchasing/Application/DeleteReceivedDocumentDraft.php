<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Attachment\Application\PurgeAttachments;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;

/**
 * Deletes a draft for good, in the trash or not, together with its file: an
 * upload by mistake leaves nothing behind but the audit log. A draft has no
 * evidence number, so its series keeps no gap. A recorded document only goes
 * to the trash.
 */
final readonly class DeleteReceivedDocumentDraft
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private PurgeAttachments $purge,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a document of another tenant
     * @throws ReceivedDocumentRefused with RECORDED for a recorded document
     */
    public function delete(string $documentId): void
    {
        $this->access->denyUnlessStaff();
        $files = $this->unit->run(function () use ($documentId): array {
            $document = $this->documents->lock($documentId);
            if ($document->isRecorded()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::RECORDED);
            }
            $files = $this->purge->forget(ReceivedDocument::ATTACHMENT_KIND, [$document->id()]);
            $this->documents->remove($document);

            return $files;
        });
        // The files go once their records are gone for good.
        $this->purge->removeFiles($files);
    }
}
