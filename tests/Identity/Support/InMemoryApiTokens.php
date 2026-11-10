<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Support;

use App\Identity\Application\ApiTokenRecord;
use App\Identity\Application\ApiTokens;
use App\Identity\Domain\ApiToken;
use App\Tenancy\Domain\TenantId;

/**
 * The tokens of one tenant in memory, and records across tenants as given.
 */
final class InMemoryApiTokens implements ApiTokens
{
    /** @var list<ApiToken> */
    public array $tokens = [];

    /** @var list<ApiTokenRecord> */
    public array $records = [];

    /** @var list<array{string, \DateTimeImmutable, \DateTimeImmutable}> */
    public array $touches = [];

    /** @var list<string> */
    public array $hashesLookedUp = [];

    public int $flushes = 0;

    public function add(ApiToken $token): void
    {
        $this->tokens[] = $token;
    }

    public function find(string $id): ?ApiToken
    {
        foreach ($this->tokens as $token) {
            if ($token->id() === $id) {
                return $token;
            }
        }

        return null;
    }

    public function ofUser(string $userId): array
    {
        return array_values(array_filter($this->tokens, static fn (ApiToken $token): bool => $token->userId() === $userId));
    }

    public function all(): array
    {
        return $this->tokens;
    }

    public function byHash(string $tokenHash): ?ApiTokenRecord
    {
        $this->hashesLookedUp[] = $tokenHash;

        return $this->records[0] ?? null;
    }

    public function records(?TenantId $tenant): array
    {
        return $this->records;
    }

    public function tenantOf(string $id): ?TenantId
    {
        return null;
    }

    public function touch(string $id, \DateTimeImmutable $now, \DateTimeImmutable $usedAfter): void
    {
        $this->touches[] = [$id, $now, $usedAfter];
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
