<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\ApiToken;

/**
 * The personal API tokens of the instance.
 */
interface ApiTokens
{
    public function add(ApiToken $token): void;

    public function find(string $id): ?ApiToken;

    /**
     * @return list<ApiToken> the personal tokens of the user, newest first; no OAuth connections
     */
    public function ofUser(string $userId): array;

    /**
     * @return list<ApiToken> every personal token, newest first; no OAuth connections
     */
    public function all(): array;

    /**
     * @return list<ApiToken> the OAuth connections (ADR 0036) of the user, or of everyone for null, newest first
     */
    public function connections(?string $userId): array;

    /**
     * Puts a new access token into an OAuth connection: an update query, so no
     * audit entry is written for the hourly refresh.
     */
    public function rotate(string $id, string $tokenHash, string $lastFour, \DateTimeImmutable $expiresAt): void;

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
