<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

/**
 * Whether a password is known from a breach or is too common to keep anyone out
 * (NIST SP 800-63B: check new passwords against such a list). An installation
 * without the internet turns the check off.
 */
interface CompromisedPasswords
{
    public function isCompromised(#[\SensitiveParameter] string $password): bool;
}
