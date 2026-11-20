<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Identity\Application\OwnerCheck;

/**
 * Who works with the taxes and contributions: the owner of the organisation,
 * and nobody else (B7), on screens and with a token alike, since the owner
 * profile holds personal data. Every use case asks here first.
 */
final readonly class TaxesAccess
{
    public function __construct(private OwnerCheck $owner)
    {
    }

    public function isOwner(): bool
    {
        return $this->owner->isOwner();
    }

    /**
     * @throws TaxesRefused owner_only
     */
    public function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw TaxesRefused::ownerOnly();
        }
    }

    /**
     * Whether a given person, signed in or not, is the owner: for work done on
     * their behalf, such as the webhooks they own.
     */
    public function isOwnerMember(string $userId): bool
    {
        return $this->owner->isOwnerMember($userId);
    }
}
