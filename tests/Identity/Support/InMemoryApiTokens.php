<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Support;

use Foresi\Identity\Application\ApiTokenRecord;
use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Domain\ApiToken;

/**
 * The tokens in memory, and the records as given.
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

    /** @var list<array{string, string, string, \DateTimeImmutable}> */
    public array $rotations = [];

    public function ofUser(string $userId): array
    {
        return array_values(array_filter($this->tokens, static fn (ApiToken $token): bool => $token->userId() === $userId && null === $token->oauthClientId()));
    }

    public function all(): array
    {
        return array_values(array_filter($this->tokens, static fn (ApiToken $token): bool => null === $token->oauthClientId()));
    }

    public function connections(?string $userId): array
    {
        return array_values(array_filter($this->tokens, static fn (ApiToken $token): bool => null !== $token->oauthClientId() && (null === $userId || $token->userId() === $userId)));
    }

    public function rotate(string $id, string $tokenHash, string $lastFour, \DateTimeImmutable $expiresAt): void
    {
        $this->rotations[] = [$id, $tokenHash, $lastFour, $expiresAt];
    }

    public function byHash(string $tokenHash): ?ApiTokenRecord
    {
        $this->hashesLookedUp[] = $tokenHash;

        return $this->records[0] ?? null;
    }

    public function records(): array
    {
        return $this->records;
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
