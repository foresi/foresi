<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Audit\Application\TokenAuditActor;
use App\Identity\Application\ApiTokenContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The audit actor is the signed-in user of the current security token, and
 * the personal API token it signed in with, if any.
 */
final readonly class SecurityAuditActor implements TokenAuditActor
{
    public function __construct(private TokenStorageInterface $tokens, private ApiTokenContext $apiTokens)
    {
    }

    public function userId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof SecurityUser ? $user->id() : null;
    }

    public function apiTokenId(): ?string
    {
        return $this->apiTokens->grant()?->tokenId;
    }
}
