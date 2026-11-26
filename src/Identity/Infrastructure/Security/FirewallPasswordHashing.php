<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\PasswordHashing;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * The hasher configured for the signed-in user (security.yaml), the same one
 * the sign-in form checks against.
 */
final readonly class FirewallPasswordHashing implements PasswordHashing
{
    public function __construct(private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return $this->hashers->getPasswordHasher(SecurityUser::class)->hash($password);
    }

    public function verify(string $hash, #[\SensitiveParameter] string $password): bool
    {
        return '' !== $password && $this->hashers->getPasswordHasher(SecurityUser::class)->verify($hash, $password);
    }
}
