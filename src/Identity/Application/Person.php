<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;

/**
 * One line of the people screen.
 */
final readonly class Person
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $name,
        public string $email,
        public Role $role,
        public ?string $contactId,
        public ?string $contactName,
        public bool $twoFactor,
        public bool $active,
    ) {
    }
}
