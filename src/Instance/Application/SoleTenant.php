<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Instance\Application;

use App\Instance\Application\Schedule\TenantDirectory;
use App\Instance\Domain\TenantId;

/**
 * The one tenant of the instance (ADR 0035), for background work that has no
 * request to take it from: the relay and the webhook sweep still stamp their
 * messages with it, as their handlers read data that is still tenant-owned. It
 * goes with the tenant itself.
 */
final readonly class SoleTenant
{
    public function __construct(private TenantDirectory $directory)
    {
    }

    /**
     * @throws NoActiveTenant when the instance has no tenant yet
     */
    public function id(): TenantId
    {
        $ids = $this->directory->ids();

        return $ids[0] ?? throw NoActiveTenant::create();
    }
}
