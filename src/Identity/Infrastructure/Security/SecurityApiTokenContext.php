<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Application\InsufficientScope;
use App\Identity\Domain\ApiScope;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The grant rides on the security token of the request, so it lives exactly as
 * long as the sign-in and is gone with it.
 */
final readonly class SecurityApiTokenContext implements ApiTokenContext
{
    public const string ATTRIBUTE = 'api_token_grant';

    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function grant(): ?ApiTokenGrant
    {
        $token = $this->tokens->getToken();
        if (!$token instanceof TokenInterface || !$token->hasAttribute(self::ATTRIBUTE)) {
            return null;
        }
        $grant = $token->getAttribute(self::ATTRIBUTE);

        return $grant instanceof ApiTokenGrant ? $grant : null;
    }

    public function requireScope(ApiScope $scope): ApiTokenGrant
    {
        $grant = $this->grant();
        if (!$grant instanceof ApiTokenGrant || !$grant->allows($scope)) {
            throw new InsufficientScope($scope);
        }

        return $grant;
    }
}
