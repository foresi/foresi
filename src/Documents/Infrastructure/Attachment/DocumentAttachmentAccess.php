<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Attachment;

use Foresi\Attachment\Application\AttachmentAccess;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Domain\Document;

/**
 * The files of a document (its generated PDF and its signed copy): the owners
 * and members of the organisation see them; guests never do. Files are added
 * through the use cases of Documents while the document is not final. A file of
 * a document that has left draft is never deleted.
 */
final readonly class DocumentAttachmentAccess implements AttachmentAccess
{
    public function __construct(private DocumentsStore $store, private DocumentsAccess $access)
    {
    }

    public function kinds(): array
    {
        return [Document::ATTACHMENT_KIND];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        return $this->document($ownerKind, $ownerId) instanceof Document;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        $document = $this->document($ownerKind, $ownerId);

        return $document instanceof Document && !$document->status()->isFinal();
    }

    public function canDelete(Attachment $attachment): bool
    {
        $document = $this->document($attachment->ownerKind(), $attachment->ownerId());

        return $document instanceof Document && $document->status()->isDraft();
    }

    private function document(string $ownerKind, string $ownerId): ?Document
    {
        if (Document::ATTACHMENT_KIND !== $ownerKind || !$this->access->isStaff()) {
            return null;
        }

        return $this->store->document($ownerId);
    }
}
