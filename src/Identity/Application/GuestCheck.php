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
 * Whether the signed-in user is a guest of the active organisation:
 * someone it shares work with, who sees none of its modules.
 */
final readonly class GuestCheck
{
    public function __construct(
        private MembershipDirectory $memberships,
        private TenantContext $context,
        private AuditActor $actor,
    ) {
    }

    public function isGuest(): bool
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->context->has()) {
            return false;
        }
        $tenant = $this->context->current();

        return array_any(
            $this->memberships->organisationsOf($userId),
            static fn (Organisation $organisation): bool => Role::Guest === $organisation->role && $organisation->id->equals($tenant),
        );
    }
}
