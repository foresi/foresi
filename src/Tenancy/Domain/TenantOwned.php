<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * Marker for entities that belong to exactly one tenant. The Doctrine tenant
 * filter scopes every query for implementors to the active tenant.
 *
 * Implementors map a non-nullable "tenant_id" column (type "tenant_id") on a
 * private "tenantId" property that defaults to null and has no setter; the
 * StampTenantListener writes it on persist.
 */
interface TenantOwned
{
    public function tenantId(): TenantId;
}
