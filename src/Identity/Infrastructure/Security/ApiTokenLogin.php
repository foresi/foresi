<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\ApiTokenAuthentication;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Signs a console process in with a personal API token, as the MCP server on
 * stdio does: the token's person becomes the signed-in user and the audit
 * actor, as on the mcp firewall.
 */
final readonly class ApiTokenLogin
{
    public function __construct(
        private ApiTokenAuthentication $authentication,
        private UserRepository $users,
        private UserProvider $provider,
        private TokenStorageInterface $tokens,
    ) {
    }

    /**
     * @throws ApiTokenRefused
     */
    public function asConsole(#[\SensitiveParameter] string $secret): ApiTokenGrant
    {
        $grant = $this->authentication->authenticate($secret);
        $user = $this->users->findById($grant->userId);
        if (!$user instanceof User) {
            throw new ApiTokenRefused(ApiTokenRefused::MEMBERSHIP_GONE);
        }
        $securityUser = $this->provider->loadUserByIdentifier($user->email());
        $token = new PostAuthenticationToken($securityUser, ApiFirewalls::MCP, $securityUser->getRoles());
        $token->setAttribute(SecurityApiTokenContext::ATTRIBUTE, $grant);
        $this->tokens->setToken($token);

        return $grant;
    }
}
