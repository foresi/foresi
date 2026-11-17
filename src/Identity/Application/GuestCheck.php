<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Domain\Role;

/**
 * Whether the signed-in user is a guest of the active organisation:
 * someone it shares work with, who sees none of its modules.
 */
final readonly class GuestCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private AuditActor $actor,
    ) {
    }

    public function isGuest(): bool
    {
        $userId = $this->actor->userId();

        return null !== $userId && Role::Guest === $this->memberships->roleOf($userId);
    }
}
