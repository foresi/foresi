<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiScope;
use App\Tenancy\Domain\TenantId;

/**
 * What a valid personal API token lets a request do: act as its person, in its
 * organisation, within its scopes.
 */
final readonly class ApiTokenGrant
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public string $userId,
        public TenantId $tenantId,
        public string $tokenId,
        public string $tokenName,
        public array $scopes,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function allows(ApiScope $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
