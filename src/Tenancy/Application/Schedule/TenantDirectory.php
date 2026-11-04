<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

use App\Tenancy\Domain\TenantId;

/**
 * Port: every tenant of the installation, for work that visits each in turn,
 * and the zone its days follow. A tenant's zone is the one stored for it, else
 * the one the TenantZoneFallback gives, else UTC.
 */
interface TenantDirectory
{
    /**
     * @return list<TenantId>
     */
    public function ids(): array;

    /**
     * The zone of every tenant, keyed by tenant id in id order, in at most two
     * queries whatever the number of tenants.
     *
     * @return array<string, \DateTimeZone>
     */
    public function zones(): array;

    /**
     * The zone of one tenant, by the same rule.
     */
    public function zone(TenantId $tenant): \DateTimeZone;
}
