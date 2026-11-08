<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Tenancy\Application\TenantContext;

/**
 * Removes for good the attachments of records their module removes for good,
 * deleted ones included: the records first, then the files, so a failure never
 * leaves a record whose file is gone. A file that outlives a failure is only
 * space. The audit log keeps that each one existed. No person's permission is
 * asked: the module of the records decides, as for a purge of its trash.
 */
final readonly class PurgeAttachments
{
    public function __construct(
        private Attachments $attachments,
        private AttachmentFiles $files,
        private TenantContext $context,
    ) {
    }

    /**
     * @param list<string> $ownerIds
     */
    public function purge(string $ownerKind, array $ownerIds): void
    {
        $attachments = $this->attachments->allOf($ownerKind, $ownerIds);
        if ([] === $attachments) {
            return;
        }
        foreach ($attachments as $attachment) {
            $this->attachments->remove($attachment);
        }
        $this->attachments->flush();
        $tenantId = $this->context->current()->toString();
        foreach ($attachments as $attachment) {
            $this->files->remove($tenantId, $attachment->storageKey());
        }
    }
}
