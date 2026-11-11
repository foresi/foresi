<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Api;

/**
 * Whether the token the request or process signed in with may read or write. The
 * REST endpoints and the MCP tools ask here, so neither needs to know where tokens
 * live. Without a token nothing is allowed.
 */
interface ScopeGuard
{
    public const string READ = 'read';
    public const string WRITE = 'write';

    /**
     * @param self::READ|self::WRITE $scope
     *
     * @throws \DomainException when the token lacks the scope, or there is no token
     */
    public function require(string $scope): void;
}
