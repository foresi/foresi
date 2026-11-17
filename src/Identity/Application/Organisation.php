<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;

/**
 * The organisation of the instance as a user sees it: its name, that of the
 * first active firm (else the product's), and the role the user holds.
 */
final readonly class Organisation
{
    public function __construct(
        public string $name,
        public Role $role,
    ) {
    }
}
