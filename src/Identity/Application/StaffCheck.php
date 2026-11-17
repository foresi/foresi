<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;

/**
 * Whether the signed-in user works for the active organisation, as its owner or a
 * member. Guests do not. Roles are membership data, so the
 * firewall does not know them.
 */
final readonly class StaffCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private AuditActor $actor,
    ) {
    }

    public function isStaff(): bool
    {
        $userId = $this->actor->userId();

        return null !== $userId && $this->isStaffMember($userId);
    }

    /**
     * Whether a given user, signed in or not, still works for the active
     * organisation as its owner or a member: for work done on their behalf, such
     * as a copy of a mail a run sends for them.
     */
    public function isStaffMember(string $userId): bool
    {
        return $this->memberships->roleOf($userId)?->isStaff() ?? false;
    }
}
