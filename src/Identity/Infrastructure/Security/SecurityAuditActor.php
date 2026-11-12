<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Audit\Application\TokenAuditActor;
use App\Audit\Domain\ActorKind;
use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The audit actor is the signed-in user of the current security token, and
 * the personal API token it signed in with, if any. A token grant on the
 * `mcp` firewall is an MCP agent, on any other firewall an API token.
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

    public function kind(): ActorKind
    {
        if (null === $this->userId()) {
            return ActorKind::System;
        }
        if (!$this->apiTokens->grant() instanceof ApiTokenGrant) {
            return ActorKind::User;
        }
        $token = $this->tokens->getToken();

        return $token instanceof PostAuthenticationToken && ApiFirewalls::MCP === $token->getFirewallName()
            ? ActorKind::McpAgent
            : ActorKind::ApiToken;
    }

    public function apiTokenId(): ?string
    {
        return $this->apiTokens->grant()?->tokenId;
    }
}
