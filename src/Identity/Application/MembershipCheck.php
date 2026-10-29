<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Tenancy\Domain\TenantId;

final class MembershipCheck
{
    /**
     * @param list<TenantId> $tenants
     */
    public static function isMember(array $tenants, TenantId $tenant): bool
    {
        return array_any($tenants, fn (TenantId $candidate): bool => $candidate->equals($tenant));
    }
}
