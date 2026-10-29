<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Audit\Application\AuditActor;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The audit actor is the signed-in user of the current security token.
 */
final readonly class SecurityAuditActor implements AuditActor
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function userId(): ?int
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof SecurityUser ? $user->id() : null;
    }
}
