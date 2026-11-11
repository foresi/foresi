<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Tenancy\Domain\TenantId;

/**
 * The personal API tokens of the active tenant, and the few reads across
 * tenants: a request names its token before any organisation is active, and
 * the operator works outside of one.
 */
interface ApiTokens
{
    public function add(ApiToken $token): void;

    public function find(string $id): ?ApiToken;

    /**
     * @return list<ApiToken> the tokens of the user in the active tenant, newest first
     */
    public function ofUser(string $userId): array;

    /**
     * @return list<ApiToken> every token of the active tenant, newest first
     */
    public function all(): array;

    /**
     * Any tenant's token with this hash.
     */
    public function byHash(string $tokenHash): ?ApiTokenRecord;

    /**
     * @return list<ApiTokenRecord> the tokens of one tenant, or of all of them, newest first
     */
    public function records(?TenantId $tenant): array;

    public function tenantOf(string $id): ?TenantId;

    /**
     * Records a use, unless one later than $usedAfter is recorded already. Any
     * tenant's token; no audit entry is written.
     */
    public function touch(string $id, \DateTimeImmutable $now, \DateTimeImmutable $usedAfter): void;

    public function flush(): void;
}
