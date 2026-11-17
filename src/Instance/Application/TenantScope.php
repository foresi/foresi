<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Instance\Application;

use App\Instance\Domain\TenantId;

/**
 * Port implemented by persistence: binds data access to a tenant. Activating
 * must also discard everything loaded under a previous tenant.
 */
interface TenantScope
{
    public function activate(TenantId $tenant): void;

    public function deactivate(): void;
}
