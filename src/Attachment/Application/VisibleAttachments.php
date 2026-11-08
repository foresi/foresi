<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;

/**
 * The attachments the person may see: of a record they may see, and one by
 * its id. What they may not see is not found.
 */
final readonly class VisibleAttachments
{
    public function __construct(private Attachments $attachments, private AttachmentPermissions $permissions)
    {
    }

    /**
     * @return list<Attachment>
     */
    public function of(string $ownerKind, string $ownerId): array
    {
        return $this->permissions->canSee($ownerKind, $ownerId) ? $this->attachments->of($ownerKind, $ownerId) : [];
    }

    public function get(string $id): ?Attachment
    {
        $attachment = $this->attachments->get($id);

        return $attachment instanceof Attachment && !$attachment->isDeleted() && $this->permissions->canSee($attachment->ownerKind(), $attachment->ownerId()) ? $attachment : null;
    }
}
