<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Identity\Domain\Role;

/**
 * A person an item is shared with.
 */
final readonly class SharedPerson
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $name,
        public Role $role,
    ) {
    }
}
