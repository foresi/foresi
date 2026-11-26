<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;

/**
 * Tells the person their password was changed, so someone who changed it in
 * their place does not go unnoticed (OWASP: notify the user of a change).
 */
interface PasswordChangedNotice
{
    public function send(User $user, \DateTimeImmutable $changedAt): void;
}
