<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\ApiTokenAuthentication;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Signs a request on the api and mcp firewalls in with the bearer token. The
 * grant travels in the badge to the security token (ApiTokenGrantOnToken).
 * The access token of an OAuth client was issued for the MCP server (RFC 8707,
 * ADR 0036), so only the handler of the mcp firewall accepts it.
 */
final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenAuthentication $authentication,
        private UserRepository $users,
        private bool $acceptsOAuth = false,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $grant = $this->authentication->authenticate($accessToken);
        } catch (ApiTokenRefused $refused) {
            throw ApiTokenRejected::because($refused->reason);
        }
        if (null !== $grant->oauthClientId && !$this->acceptsOAuth) {
            throw ApiTokenRejected::because(ApiTokenRefused::UNKNOWN);
        }
        $user = $this->users->findById($grant->userId);
        if (!$user instanceof User) {
            throw ApiTokenRejected::because(ApiTokenRefused::MEMBERSHIP_GONE);
        }

        return new UserBadge($user->email(), null, [SecurityApiTokenContext::ATTRIBUTE => $grant]);
    }
}
