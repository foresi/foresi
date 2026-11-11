<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Attachment;

use App\Attachment\Application\AttachmentAccess;
use App\Attachment\Domain\Attachment;
use App\Identity\Application\StaffCheck;
use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Domain\ReceivedDocument;

/**
 * The original file of a received document: the owners and members of the
 * organisation see it, in the trash too, as they see the document; guests
 * never do. A file is attached through the upload of the module, to a draft or
 * to a recorded document without one, and never in the trash. Nobody deletes
 * one here: a draft goes for good together with its file, and a recorded
 * document keeps the file it was recorded from.
 */
final readonly class ReceivedDocumentAttachmentAccess implements AttachmentAccess
{
    public function __construct(private ReceivedDocuments $documents, private StaffCheck $staff)
    {
    }

    public function kinds(): array
    {
        return [ReceivedDocument::ATTACHMENT_KIND];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        return $this->document($ownerKind, $ownerId) instanceof ReceivedDocument;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        $document = $this->document($ownerKind, $ownerId);

        return $document instanceof ReceivedDocument && !$document->isTrashed() && (!$document->isRecorded() || null === $document->originalAttachmentId());
    }

    public function canDelete(Attachment $attachment): bool
    {
        return false;
    }

    private function document(string $ownerKind, string $ownerId): ?ReceivedDocument
    {
        if (ReceivedDocument::ATTACHMENT_KIND !== $ownerKind || !$this->staff->isStaff()) {
            return null;
        }

        return $this->documents->find($ownerId);
    }
}
