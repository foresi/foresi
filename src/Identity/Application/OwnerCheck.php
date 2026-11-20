<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Domain\Role;

/**
 * Whether the signed-in user owns the active organisation. Roles are membership
 * data, so the firewall does not know them.
 */
final readonly class OwnerCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private AuditActor $actor,
    ) {
    }

    public function isOwner(): bool
    {
        $userId = $this->actor->userId();

        return null !== $userId && $this->isOwnerMember($userId);
    }

    /**
     * Whether a given user, signed in or not, owns the active organisation:
     * for work done on their behalf, such as the webhooks they own.
     */
    public function isOwnerMember(string $userId): bool
    {
        return Role::Owner === $this->memberships->roleOf($userId);
    }
}
