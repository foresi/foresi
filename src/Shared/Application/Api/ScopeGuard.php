<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Api;

/**
 * Whether the token the request or process signed in with has a scope, such as
 * "contacts:read" or "webhooks:write" (ADR 0032). The REST endpoints and the
 * MCP tools ask here, so neither needs to know where tokens live. "write"
 * grants every scope, "read" every read, and "contacts:write" "contacts:read".
 * Without a token nothing is allowed.
 */
interface ScopeGuard
{
    /** Any valid token will do, whatever its scopes, such as for whoami. */
    public const string ANY = '';

    /**
     * @param string $scope a scope such as "contacts:write", or ANY
     *
     * @throws \DomainException when the token lacks the scope, or there is no token
     */
    public function require(string $scope): void;

    /**
     * The same check without the exception, such as for listing the tools a token may call.
     */
    public function allows(string $scope): bool;
}
