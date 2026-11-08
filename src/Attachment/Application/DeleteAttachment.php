<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use Psr\Clock\ClockInterface;

/**
 * Takes an attachment off its record: the person who uploaded it, or whom the
 * module of the record lets (its owners). The file stays until a purge.
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
    public function delete(string $id): void
    {
        $attachment = $this->attachments->get($id);
        if (!$attachment instanceof Attachment || !$this->permissions->canSee($attachment->ownerKind(), $attachment->ownerId())) {
            throw new AttachmentRefused(AttachmentRefused::UNKNOWN);
        }
        $userId = $this->actor->userId();
        if (null === $userId || ($attachment->uploadedBy() !== $userId && !$this->permissions->canDelete($attachment))) {
            throw new AttachmentRefused(AttachmentRefused::NOT_ALLOWED);
        }
        $this->correlation->within($this->correlation->current() ?? 'attachment:'.$id, function () use ($attachment): void {
            $attachment->delete($this->clock->now());
            $this->attachments->flush();
        });
    }
}
