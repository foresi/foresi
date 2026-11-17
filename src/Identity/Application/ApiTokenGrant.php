<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiScope;

/**
 * What a valid personal API token lets a request do: act as its person within its
 * scopes.
 */
final readonly class ApiTokenGrant
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public string $userId,
        public string $tokenId,
        public string $tokenName,
        public array $scopes,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * Whether one of the token's scopes grants this one: "write" everything,
     * "read" every read, "contacts:write" also "contacts:read".
     */
    public function allows(ApiScope $scope): bool
    {
        return array_any($this->scopes, static fn (ApiScope $granted): bool => $granted->grants($scope));
    }
}
