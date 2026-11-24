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
     * @param DocumentSource         $source how the drafts came in: dropped on the screen, or sent over the API and MCP
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

        return array_map(fn (AttachmentUpload $file): UploadedDocument => $this->one($file, $userId, $source), $files);
    }

    private function one(AttachmentUpload $file, string $userId, DocumentSource $source): UploadedDocument
    {
        try {
            [$document, $message] = $this->unit->run(function () use ($file, $userId, $source): array {
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $document = new ReceivedDocument(ReceivedDocumentContent::blank($this->instanceClock->today()), $userId, $now, $source);
                $this->documents->add($document);
                // The store asks the module whether the document may take the file, and saves both.
                $document->attachOriginal($this->upload->upload(ReceivedDocument::ATTACHMENT_KIND, $document->id(), $file)->id());
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
