<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Support;

use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\RefreshToken;

/**
 * The OAuth clients, codes and refresh tokens in memory. A claim only succeeds
 * once per id, as the conditional update of the database does.
 */
final class InMemoryOAuthStore implements OAuthStore
{
    /** @var list<OAuthClient> */
    public array $clients = [];

    /** @var list<AuthorizationCode> */
    public array $codes = [];

    /** @var list<RefreshToken> */
    public array $refreshTokens = [];

    /** @var array<string, \DateTimeImmutable> when each code or refresh token was claimed, by id */
    public array $claimed = [];

    /** @var array<string, string> the API token of each code, by code id */
    public array $attached = [];

    /** The id whose claim fails, as when another request came first. */
    public ?string $lostRace = null;

    public int $flushes = 0;

    public function addClient(OAuthClient $client): void
    {
        $this->clients[] = $client;
    }

    public function clientByClientId(string $clientId): ?OAuthClient
    {
        return array_find($this->clients, static fn (OAuthClient $client): bool => $client->clientId() === $clientId);
    }

    public function client(string $id): ?OAuthClient
    {
        return array_find($this->clients, static fn (OAuthClient $client): bool => $client->id() === $id);
    }

    public function addCode(AuthorizationCode $code): void
    {
        $this->codes[] = $code;
    }

    public function codeByHash(string $codeHash): ?AuthorizationCode
    {
        return array_find($this->codes, static fn (AuthorizationCode $code): bool => self::read($code, 'codeHash') === $codeHash);
    }

    public function claimCode(string $id, \DateTimeImmutable $now): bool
    {
        return $this->claim(array_find($this->codes, static fn (AuthorizationCode $code): bool => $code->id() === $id), $now);
    }

    public function attachToken(string $codeId, string $apiTokenId): void
    {
        $this->attached[$codeId] = $apiTokenId;
        $code = array_find($this->codes, static fn (AuthorizationCode $code): bool => $code->id() === $codeId);
        if ($code instanceof AuthorizationCode) {
            self::write($code, 'apiTokenId', $apiTokenId);
        }
    }

    public function addRefreshToken(RefreshToken $token): void
    {
        $this->refreshTokens[] = $token;
    }

    public function refreshTokenByHash(string $tokenHash): ?RefreshToken
    {
        return array_find($this->refreshTokens, static fn (RefreshToken $token): bool => self::read($token, 'tokenHash') === $tokenHash);
    }

    public function claimRefreshToken(string $id, \DateTimeImmutable $now): bool
    {
        return $this->claim(array_find($this->refreshTokens, static fn (RefreshToken $token): bool => $token->id() === $id), $now);
    }

    public function flush(): void
    {
        ++$this->flushes;
    }

    /**
     * Marks a code or a refresh token used, as the conditional update of the database does.
     */
    public static function markUsed(AuthorizationCode|RefreshToken $entity, \DateTimeImmutable $now): void
    {
        self::write($entity, 'usedAt', $now);
    }

    private function claim(AuthorizationCode|RefreshToken|null $entity, \DateTimeImmutable $now): bool
    {
        if (null === $entity || $entity->id() === $this->lostRace || $entity->isUsed()) {
            return false;
        }
        $this->claimed[$entity->id()] = $now;
        self::markUsed($entity, $now);

        return true;
    }

    private static function read(object $entity, string $property): mixed
    {
        return new \ReflectionProperty($entity, $property)->getValue($entity);
    }

    private static function write(object $entity, string $property, mixed $value): void
    {
        new \ReflectionProperty($entity, $property)->setValue($entity, $value);
    }
}
