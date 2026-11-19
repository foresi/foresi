<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditActor;

/**
 * The attachments the person may see: of a record they may see, and one by
 * its id. What they may not see is not found. A deleted attachment is never
 * served; only whoever may restore it finds it, in deletedOf.
 */
final readonly class VisibleAttachments
{
    public function __construct(
        private Attachments $attachments,
        private AttachmentPermissions $permissions,
        private AuditActor $actor,
    ) {
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

    /**
     * The deleted attachments of a record that the person may restore, the
     * most recently deleted first.
     *
     * @return list<Attachment>
     */
    public function deletedOf(string $ownerKind, string $ownerId): array
    {
        if (!$this->permissions->canSee($ownerKind, $ownerId)) {
            return [];
        }
        $userId = $this->actor->userId();

        return array_values(array_filter(
            $this->attachments->deletedOf($ownerKind, $ownerId),
            fn (Attachment $attachment): bool => $this->permissions->canRemove($attachment, $userId),
        ));
    }

    /**
     * Whether the person may delete the attachment, which they see.
     */
    public function canDelete(Attachment $attachment): bool
    {
        return $this->permissions->canRemove($attachment, $this->actor->userId());
    }
}
