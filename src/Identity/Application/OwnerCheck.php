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
 * Whether the signed-in user owns the active organisation. Roles are membership
 * data, so the firewall does not know them.
 */
final readonly class OwnerCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private TenantContext $context,
        private AuditActor $actor,
    ) {
    }

    public function isOwner(): bool
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->context->has()) {
            return false;
        }
        $tenant = $this->context->current();

        return array_any(
            $this->memberships->organisationsOf($userId),
            static fn (Organisation $organisation): bool => Role::Owner === $organisation->role && $organisation->id->equals($tenant),
        );
    }
}
