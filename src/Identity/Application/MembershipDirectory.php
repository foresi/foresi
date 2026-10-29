<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Tenancy\Domain\TenantId;

/**
 * Answers which tenants a user belongs to, whatever tenant is active.
 */
interface MembershipDirectory
{
    /**
     * @return list<TenantId>
     */
    public function tenantIdsOf(User $user): array;
}
