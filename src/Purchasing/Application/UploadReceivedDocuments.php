<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Purchasing\Application\Reading\DocumentReadings;
use Foresi\Purchasing\Application\Reading\ReadingQueue;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Psr\Clock\ClockInterface;

/**
 * Files dropped for a firm, one or many: each becomes a draft received today
 * with the file as its original in the attachment store, to be completed and
 * recorded later. Each file is saved on its own, so one the store refuses
 * leaves no draft and does not stop the others.
 *
 * Each draft is then read in the background (ReadDocument), queued once the
 * draft is stored: a job queued inside a transaction that rolls back would
 * look for a draft that is not there.
 */
final readonly class UploadReceivedDocuments
{
    public function __construct(
        private PurchasingAccess $access,
        private StaffCheck $staff,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private UploadAttachment $upload,
        private DocumentWarnings $warnings,
        private DocumentReadings $readings,
        private ReadingQueue $queue,
        private AuditCorrelation $correlation,
        private InstanceClock $instanceClock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<AttachmentUpload> $files
     * @param DocumentSource         $source how the drafts came in: dropped on the screen, sent over the API and MCP, or by mail
     *
     * @return list<UploadedDocument> one per file, in their order
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     * @throws NoFirmYet
     */
    public function upload(array $files, DocumentSource $source = DocumentSource::Upload): array
    {
        $userId = $this->access->denyUnlessStaff();
        $this->access->firm();

        return array_map(fn (AttachmentUpload $file): UploadedDocument => $this->one($file, $userId, $source, null, false), $files);
    }

    /**
     * The same upload for a job that runs with nobody signed in, on behalf of
     * a person who is the uploader: a mail's attachments filed for the owner
     * of the address it came to. The person must still work for the
     * organisation; the attachment store asks again whether they may attach.
     *
     * @param list<AttachmentUpload> $files
     * @param string|null            $sourceMailId the Mail message the files came with
     *
     * @return list<UploadedDocument> one per file, in their order
     *
     * @throws PurchasingNotAllowed when the person no longer works for the organisation
     * @throws NoFirmYet
     */
    public function uploadFor(string $ownerUserId, array $files, DocumentSource $source, ?string $sourceMailId = null): array
    {
        if (!$this->staff->isStaffMember($ownerUserId)) {
            throw new PurchasingNotAllowed();
        }
        $this->access->firm();

        return array_map(fn (AttachmentUpload $file): UploadedDocument => $this->one($file, $ownerUserId, $source, $sourceMailId, true), $files);
    }

    private function one(AttachmentUpload $file, string $userId, DocumentSource $source, ?string $sourceMailId, bool $onBehalf): UploadedDocument
    {
        try {
            [$document, $message] = $this->unit->run(function () use ($file, $userId, $source, $sourceMailId, $onBehalf): array {
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $document = new ReceivedDocument(ReceivedDocumentContent::blank($this->instanceClock->today()), $userId, $now, $source, $sourceMailId);
                $this->documents->add($document);
                // The store asks the module whether the document may take the file, and saves both.
                $stored = $onBehalf
                    ? $this->upload->uploadFor($userId, ReceivedDocument::ATTACHMENT_KIND, $document->id(), $file)
                    : $this->upload->upload(ReceivedDocument::ATTACHMENT_KIND, $document->id(), $file);
                $document->attachOriginal($stored->id());
                $this->readings->add(new DocumentReading($document, $document->version(), $now));

                return [$document, new ReadReceivedDocument($document->id(), $document->version(), false, $this->correlation->current())];
            });
        } catch (AttachmentRefused $refused) {
            return UploadedDocument::refused($file->clientName, $refused->reason);
        }
        $this->queue->queue($message);

        return UploadedDocument::drafted($file->clientName, $document->id(), $this->warnings->of($document));
    }
}
