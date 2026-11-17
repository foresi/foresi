<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Domain\Role;

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

        return null !== $userId && Role::Owner === $this->memberships->roleOf($userId);
    }
}
