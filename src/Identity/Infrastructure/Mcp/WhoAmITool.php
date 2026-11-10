<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mcp;

use App\Identity\Application\InsufficientScope;
use App\Identity\Application\Query\CurrentApiIdentity;
use App\Identity\Domain\ApiScope;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * Who the MCP client acts as: the person, the organisation and the token,
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

    public function description(): string
    {
        return 'Returns the person and the organisation this connection acts as, and the name, scopes and expiry of its API token. Every other tool reads the data of this organisation only.';
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
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, 'The token does not have the "read" scope.');
        }
        $token = $identity->token;

        return new ToolOutput(
            [
                'user' => ['id' => $identity->userId, 'email' => $identity->email],
                'tenant' => ['id' => $identity->tenantId->toString(), 'name' => $identity->tenantName],
                'token' => [
                    'id' => $token->tokenId,
                    'name' => $token->tokenName,
                    'scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $token->scopes),
                    'expires_at' => $token->expiresAt->format(\DATE_ATOM),
                ],
            ],
            'The person, organisation and API token of this connection.',
        );
    }
}
