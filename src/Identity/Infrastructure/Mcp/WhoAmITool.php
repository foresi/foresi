<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Mcp;

use Foresi\Identity\Application\InsufficientScope;
use Foresi\Identity\Application\Query\CurrentApiIdentity;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * Who the MCP client acts as: the person and the token,
 * the same answer as GET /api/v1/me.
 */
final readonly class WhoAmITool implements ReadTool
{
    public function __construct(private CurrentApiIdentity $identity)
    {
    }

    public function name(): string
    {
        return 'foresi_whoami';
    }

    public function title(): string
    {
        return 'Who am I';
    }

    public function scope(): string
    {
        return ScopeGuard::ANY;
    }

    public function description(): string
    {
        return 'Returns the person this connection acts as, and the name, scopes and expiry of its API token.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $identity = $this->identity->get();
        } catch (InsufficientScope) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, 'The connection has no valid token.');
        }
        $token = $identity->token;

        return new ToolOutput(
            [
                'user' => ['id' => $identity->userId, 'email' => $identity->email],
                'token' => [
                    'id' => $token->tokenId,
                    'name' => $token->tokenName,
                    'scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $token->scopes),
                    'expires_at' => $token->expiresAt->format(\DATE_ATOM),
                ],
            ],
            'The person and API token of this connection.',
        );
    }
}
