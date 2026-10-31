<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Stores the language a user wants the application in.
 */
final readonly class ChangeLocale
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(string $userId, string $locale): void
    {
        $user = $this->users->findById($userId) ?? throw new \DomainException('The user does not exist.');
        $user->changeLocale($locale);
        $this->users->save($user);
    }
}
