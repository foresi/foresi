<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Tenancy\Domain\TenantId;

/**
 * Remembers the tenant a signed-in user chose, between requests.
 */
interface ActiveTenantStore
{
    public function get(): ?TenantId;

    public function store(TenantId $tenant): void;

    public function clear(): void;

    /**
     * Starts a new session identifier while keeping the stored choice, so a
     * changed organisation never rides on an identifier issued before.
     */
    public function migrate(): void;
}
