<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;

/**
 * Asks the module that owns the kind of record. A kind no module claims is
 * denied, so a file can never be reached around its record.
 */
final class AttachmentPermissions
{
    /** @var array<string, AttachmentAccess>|null */
    private ?array $byKind = null;

    /**
     * @param iterable<AttachmentAccess> $accesses
     */
    public function __construct(private readonly iterable $accesses)
    {
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        return $this->of($ownerKind)?->canSee($ownerKind, $ownerId) ?? false;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        $access = $this->of($ownerKind);

        return $access instanceof AttachmentAccess && $access->canSee($ownerKind, $ownerId) && $access->canAttach($ownerKind, $ownerId);
    }

    public function canDelete(Attachment $attachment): bool
    {
        return $this->of($attachment->ownerKind())?->canDelete($attachment) ?? false;
    }

    private function of(string $ownerKind): ?AttachmentAccess
    {
        if (null === $this->byKind) {
            $byKind = [];
            foreach ($this->accesses as $access) {
                foreach ($access->kinds() as $kind) {
                    if (isset($byKind[$kind])) {
                        throw new \LogicException(sprintf('Two modules answer for attachments of "%s".', $kind));
                    }
                    $byKind[$kind] = $access;
                }
            }
            $this->byKind = $byKind;
        }

        return $this->byKind[$ownerKind] ?? null;
    }
}
