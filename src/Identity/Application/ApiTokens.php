<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;

/**
 * The personal API tokens of the instance.
 */
interface ApiTokens
{
    public function add(ApiToken $token): void;

    public function find(string $id): ?ApiToken;

    /**
     * @return list<ApiToken> the tokens of the user, newest first
     */
    public function ofUser(string $userId): array;

    /**
     * @return list<ApiToken> every token, newest first
     */
    public function all(): array;

    /**
     * The token with this hash.
     */
    public function byHash(string $tokenHash): ?ApiTokenRecord;

    /**
     * @return list<ApiTokenRecord> every token, newest first
     */
    public function records(): array;

    /**
     * Records a use, unless one later than $usedAfter is recorded already. No
     * audit entry is written.
     */
    public function touch(string $id, \DateTimeImmutable $now, \DateTimeImmutable $usedAfter): void;

    public function flush(): void;
}
