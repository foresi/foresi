<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Application\AuditCorrelation;
use Psr\Clock\ClockInterface;

/**
 * Takes an attachment off its record, and puts it back: the person who
 * uploaded it, or whom the module of the record lets (its owners). A deleted
 * file is kept for 30 days, so it can be restored, as Notion and Linear keep
 * what was deleted for 30 days; then PurgeDeletedAttachments removes it.
 */
final readonly class DeleteAttachment
{
    public function __construct(
        private Attachments $attachments,
        private AttachmentPermissions $permissions,
        private AuditActor $actor,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws AttachmentRefused
     */
    public function delete(string $id): Attachment
    {
        $attachment = $this->attachments->get($id);
        $userId = $this->allowed($attachment);
        $this->correlation->within($this->correlation->current() ?? 'attachment:'.$id, function () use ($attachment, $userId): void {
            $attachment->delete($userId, $this->clock->now());
            $this->attachments->flush();
        });

        return $attachment;
    }

    /**
     * Puts a deleted attachment back on its record. One of a record the
     * person no longer sees, or already purged, is unknown.
     *
     * @throws AttachmentRefused
     */
    public function restore(string $id): Attachment
    {
        $attachment = $this->attachments->withDeleted($id);
        $this->allowed($attachment);
        if (!$attachment->isDeleted()) {
            return $attachment;
        }
        $this->correlation->within($this->correlation->current() ?? 'attachment:'.$id, function () use ($attachment): void {
            $attachment->restore();
            $this->attachments->flush();
        });

        return $attachment;
    }

    /**
     * @return string the user id of who does it
     *
     * @throws AttachmentRefused
     *
     * @phpstan-assert Attachment $attachment
     */
    private function allowed(?Attachment $attachment): string
    {
        if (!$attachment instanceof Attachment || !$this->permissions->canSee($attachment->ownerKind(), $attachment->ownerId())) {
            throw new AttachmentRefused(AttachmentRefused::UNKNOWN);
        }
        $userId = $this->actor->userId();
        if (null === $userId || !$this->permissions->canRemove($attachment, $userId)) {
            throw new AttachmentRefused(AttachmentRefused::NOT_ALLOWED);
        }

        return $userId;
    }
}
