<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;

/**
 * Makes another of the user's tenants the active one. Refuses tenants the
 * user is not a member of.
 */
final readonly class SwitchTenant
{
    public function __construct(
        private MembershipDirectory $memberships,
        private ActiveTenantStore $store,
        private TenantContext $context,
    ) {
    }

    public function __invoke(User $user, TenantId $tenant): void
    {
        if (!MembershipCheck::isMember($this->memberships->tenantIdsOf($user), $tenant)) {
            throw new \DomainException('The user is not a member of this tenant.');
        }

        $this->store->store($tenant);
        $this->context->switchTo($tenant);
    }
}
