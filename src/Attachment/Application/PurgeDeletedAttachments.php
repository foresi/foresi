<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditCorrelation;

/**
 * Removes for good the attachments deleted more than 30 days ago, as tasks
 * leave the trash: the records first, then their files once that is saved,
 * which frees the space. The audit log keeps that each one existed.
 */
final readonly class PurgeDeletedAttachments
{
    public const int DAYS = 30;

    public function __construct(
        private Attachments $attachments,
        private PurgeAttachments $purge,
        private AuditCorrelation $correlation,
    ) {
    }

    /**
     * @return int how many attachments were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        $expired = $this->attachments->deletedBefore($now->modify(sprintf('-%d days', self::DAYS)));
        if ([] === $expired) {
            return 0;
        }
        $keys = array_map(static fn (Attachment $attachment): string => $attachment->storageKey(), $expired);
        $this->correlation->within($this->correlation->current() ?? 'attachment-trash-purge:'.$now->format('Y-m-d'), function () use ($expired): void {
            foreach ($expired as $attachment) {
                $this->attachments->remove($attachment);
            }
            $this->attachments->flush();
        });
        $this->purge->removeFiles($keys);

        return \count($expired);
    }
}
