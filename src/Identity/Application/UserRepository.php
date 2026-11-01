<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> display name by id; an id without a user is left out
     */
    public function namesOf(array $ids): array;

    public function save(User $user): void;
}
