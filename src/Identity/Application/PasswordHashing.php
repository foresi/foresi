<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

/**
 * Hashes and checks sign-in passwords with the hasher the firewall uses, so a
 * password set here is the one the sign-in form accepts.
 */
interface PasswordHashing
{
    public function hash(#[\SensitiveParameter] string $password): string;

    public function verify(string $hash, #[\SensitiveParameter] string $password): bool;
}
