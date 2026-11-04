<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Domain\Role;
use App\Tenancy\Application\TenantContext;

/**
 * Whether the signed-in user works for the active organisation, as its owner or a
 * member. Subcontractors and clients do not. Roles are membership data, so the
 * firewall does not know them.
 */
final readonly class StaffCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private TenantContext $context,
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
        if (!$this->context->has()) {
            return false;
        }
        $tenant = $this->context->current();

        return array_any(
            $this->memberships->organisationsOf($userId),
            static fn (Organisation $organisation): bool => \in_array($organisation->role, [Role::Owner, Role::Member], true) && $organisation->id->equals($tenant),
        );
    }
}
