<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\Membership;

/**
 * The people of the organisation: the memberships with their users.
 */
interface People
{
    /**
     * @return list<Person> active ones first, then by name
     */
    public function all(): array;

    public function membership(string $id): ?Membership;

    /**
     * The membership of a user, active or not.
     */
    public function membershipOf(string $userId): ?Membership;

    public function activeOwners(): int;

    public function add(Membership $membership): void;

    public function flush(): void;
}
