<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;
use App\Tenancy\Domain\TenantId;

/**
 * An organisation (tenant) a user belongs to, with the role held there.
 */
final readonly class Organisation
{
    public function __construct(
        public TenantId $id,
        public string $name,
        public Role $role,
    ) {
    }
}
