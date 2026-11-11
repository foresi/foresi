<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Attachment\Application\AttachmentRefused;
use App\Attachment\Application\AttachmentUpload;
use App\Attachment\Application\UploadAttachment;
use App\Party\Application\UnknownLegalEntity;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentContent;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;

/**
 * Files dropped for a firm, one or many: each becomes a draft received today
 * with the file as its original in the attachment store, to be completed and
 * recorded later. Each file is saved on its own, so one the store refuses
 * leaves no draft and does not stop the others.
 */
final readonly class UploadReceivedDocuments
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private UploadAttachment $upload,
        private DocumentWarnings $warnings,
        private TenantClock $tenantClock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<AttachmentUpload> $files
     *
     * @return list<UploadedDocument> one per file, in their order
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownLegalEntity      also for a firm of another tenant
     * @throws ReceivedDocumentRefused with FIRM_ARCHIVED for an archived firm
     */
    public function upload(string $firmId, array $files): array
    {
        $userId = $this->access->denyUnlessStaff();
        $this->access->activeFirm($firmId);

        return array_map(fn (AttachmentUpload $file): UploadedDocument => $this->one($firmId, $file, $userId), $files);
    }

    private function one(string $firmId, AttachmentUpload $file, string $userId): UploadedDocument
    {
        try {
            $document = $this->unit->run(function () use ($firmId, $file, $userId): ReceivedDocument {
                $document = new ReceivedDocument($this->access->activeFirm($firmId), ReceivedDocumentContent::blank($this->tenantClock->today()), $userId, \DateTimeImmutable::createFromInterface($this->clock->now()));
                $this->documents->add($document);
                // The store asks the module whether the document may take the file, and saves both.
                $document->attachOriginal($this->upload->upload(ReceivedDocument::ATTACHMENT_KIND, $document->id(), $file)->id());

                return $document;
            });
        } catch (AttachmentRefused $refused) {
            return UploadedDocument::refused($file->clientName, $refused->reason);
        }

        return UploadedDocument::drafted($file->clientName, $document->id(), $this->warnings->of($document));
    }
}
