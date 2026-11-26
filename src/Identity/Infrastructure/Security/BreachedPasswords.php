<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\CompromisedPasswords;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Asks Have I Been Pwned through Symfony's NotCompromisedPassword: only the
 * first five characters of the password's SHA-1 leave the server (k-anonymity),
 * never the password. APP_PASSWORD_BREACH_CHECK=false turns it off for an
 * installation without the internet. When the service cannot be reached the
 * password is let through: an outage must not keep anyone from changing theirs.
 */
final readonly class BreachedPasswords implements CompromisedPasswords
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function isCompromised(#[\SensitiveParameter] string $password): bool
    {
        return \count($this->validator->validate($password, new NotCompromisedPassword(skipOnError: true))) > 0;
    }
}
