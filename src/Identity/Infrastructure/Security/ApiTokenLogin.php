<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenAuthentication;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Signs a console process in with a personal API token, as the MCP server on
 * stdio does: the token's person becomes the signed-in user and the audit
 * actor, and its organisation the active tenant, as on the mcp firewall.
 */
final readonly class ApiTokenLogin
{
    public function __construct(
        private ApiTokenAuthentication $authentication,
        private UserRepository $users,
        private UserProvider $provider,
        private TokenStorageInterface $tokens,
        private TenantContext $context,
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
        $this->context->set($grant->tenantId);

        return $grant;
    }
}
