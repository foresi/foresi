<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

/**
 * Marks a background message whose handler works on tenant data. It must reach
 * a worker carrying the tenant it was dispatched in; one that does not is
 * rejected instead of being handled without a tenant.
 */
interface TenantScopedMessage
{
}
