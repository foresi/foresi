<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck as StaffCheckContract;

/**
 * Whether the signed-in user works for the active organisation, as its owner or a
 * member, from the memberships. Other modules use it as
 * Foresi\Identity\Contract\StaffCheck.
 */
final readonly class StaffCheck implements StaffCheckContract
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

    public function isStaffMember(string $userId): bool
    {
        return $this->memberships->roleOf($userId)?->isStaff() ?? false;
    }
}
