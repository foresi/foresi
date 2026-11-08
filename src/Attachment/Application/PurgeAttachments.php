<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Tenancy\Application\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * Removes for good the attachments of records their module removes for good,
 * deleted ones included, in two steps: the records within the module's own
 * transaction, then the files once it is committed. A record is never left
 * without its file; a file that cannot be removed is only space, logged and
 * left behind. The audit log keeps that each one existed. No person's
 * permission is asked: the module of the records decides, as for a purge of
 * its trash.
 */
final readonly class PurgeAttachments
{
    public function __construct(
        private Attachments $attachments,
        private AttachmentFiles $files,
        private TenantContext $context,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Removes the records, saved with the caller's next flush.
     *
     * @param list<string> $ownerIds
     *
     * @return list<string> the storage keys of their files, for removeFiles once the removal is committed
     */
    public function forget(string $ownerKind, array $ownerIds): array
    {
        $keys = [];
        foreach ($this->attachments->allOf($ownerKind, $ownerIds) as $attachment) {
            $this->attachments->remove($attachment);
            $keys[] = $attachment->storageKey();
        }

        return $keys;
    }

    /**
     * @param list<string> $keys of records already removed
     */
    public function removeFiles(array $keys): void
    {
        $tenantId = $this->context->current()->toString();
        foreach ($keys as $key) {
            try {
                $this->files->remove($tenantId, $key);
            } catch (\Throwable $failure) {
                $this->logger->warning('The file of a purged attachment could not be removed and is left behind.', ['key' => $key, 'exception' => $failure]);
            }
        }
    }
}
