<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Attachment;

use Foresi\Attachment\Application\UserAttachmentAccess;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * The original file of a received document: the owners and members of the
 * organisation see it, in the trash too, as they see the document; guests
 * never do. A file is attached through the upload of the module, to a draft or
 * to a recorded document without one, and never in the trash. Nobody deletes
 * one here: a draft goes for good together with its file, and a recorded
 * document keeps the file it was recorded from.
 */
final readonly class ReceivedDocumentAttachmentAccess implements UserAttachmentAccess
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
        return $this->document($ownerKind, $ownerId, $this->staff->isStaff()) instanceof ReceivedDocument;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        return $this->mayAttach($this->document($ownerKind, $ownerId, $this->staff->isStaff()));
    }

    public function canSeeAs(string $userId, string $ownerKind, string $ownerId): bool
    {
        return $this->document($ownerKind, $ownerId, $this->staff->isStaffMember($userId)) instanceof ReceivedDocument;
    }

    public function canAttachAs(string $userId, string $ownerKind, string $ownerId): bool
    {
        return $this->mayAttach($this->document($ownerKind, $ownerId, $this->staff->isStaffMember($userId)));
    }

    public function canDelete(Attachment $attachment): bool
    {
        return false;
    }

    private function mayAttach(?ReceivedDocument $document): bool
    {
        return $document instanceof ReceivedDocument && !$document->isTrashed() && (!$document->isRecorded() || null === $document->originalAttachmentId());
    }

    private function document(string $ownerKind, string $ownerId, bool $staff): ?ReceivedDocument
    {
        if (ReceivedDocument::ATTACHMENT_KIND !== $ownerKind || !$staff) {
            return null;
        }

        return $this->documents->find($ownerId);
    }
}
