<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

use App\Tenancy\Domain\TenantId;

/**
 * Holds the tenant the current request or command works in. Queries on
 * tenant-owned data fail closed until a tenant is active.
 */
final class TenantContext
{
    private ?TenantId $current = null;

    public function __construct(private readonly TenantScope $scope)
    {
    }

    /**
     * Selects the tenant when none is active. Re-setting the same tenant is a
     * no-op; a different one must go through switchTo().
     */
    public function set(TenantId $id): void
    {
        if ($this->current instanceof TenantId) {
            if ($this->current->equals($id)) {
                return;
            }

            throw TenantAlreadySet::create();
        }

        $this->switchTo($id);
    }

    /**
     * The only way to change tenant: rebinds data access and discards all
     * entities (and unflushed changes) loaded under the previous tenant.
     */
    public function switchTo(TenantId $id): void
    {
        $this->scope->activate($id);
        $this->current = $id;
    }

    public function current(): TenantId
    {
        return $this->current ?? throw NoActiveTenant::create();
    }

    public function has(): bool
    {
        return $this->current instanceof TenantId;
    }

    public function reset(): void
    {
        $this->scope->deactivate();
        $this->current = null;
    }
}
