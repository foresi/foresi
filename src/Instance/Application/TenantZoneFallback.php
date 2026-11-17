<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Instance\Application;

use App\Instance\Domain\TenantId;

/**
 * Port: the zone of tenants that have none stored, from what a module built on
 * Instance knows about them. Implemented by Invoicing, from the country of the
 * first active firm; Instance never depends on it in return.
 */
interface TenantZoneFallback
{
    /**
     * In one query for all of them, without a tenant being active. A tenant the
     * fallback knows no zone for is left out.
     *
     * @param list<TenantId> $tenants
     *
     * @return array<string, \DateTimeZone> keyed by tenant id
     */
    public function zonesFor(array $tenants): array;
}
