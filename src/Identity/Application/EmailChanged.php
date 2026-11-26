<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;

/**
 * A confirmed e-mail change: the user, now with the new address, and the address before.
 */
final readonly class EmailChanged
{
    public function __construct(public User $user, public string $oldEmail)
    {
    }
}
