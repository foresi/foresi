<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Support;

use App\Identity\Application\ActiveTenantStore;
use App\Tenancy\Domain\TenantId;

final class InMemoryActiveTenantStore implements ActiveTenantStore
{
    public ?TenantId $tenant = null;
    public int $migrations = 0;

    public function get(): ?TenantId
    {
        return $this->tenant;
    }

    public function store(TenantId $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function migrate(): void
    {
        ++$this->migrations;
    }
}
