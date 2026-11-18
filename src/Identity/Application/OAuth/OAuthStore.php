<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\RefreshToken;

/**
 * The OAuth clients, authorization codes and refresh tokens of the instance.
 */
interface OAuthStore
{
    public function addClient(OAuthClient $client): void;

    public function clientByClientId(string $clientId): ?OAuthClient;

    public function client(string $id): ?OAuthClient;

    public function addCode(AuthorizationCode $code): void;

    public function codeByHash(string $codeHash): ?AuthorizationCode;

    /**
     * Marks the code used unless it is used already; false when another
     * exchange came first.
     */
    public function claimCode(string $id, \DateTimeImmutable $now): bool;

    /**
     * Remembers the API token the code was exchanged for.
     */
    public function attachToken(string $codeId, string $apiTokenId): void;

    public function addRefreshToken(RefreshToken $token): void;

    public function refreshTokenByHash(string $tokenHash): ?RefreshToken;

    /**
     * Marks the refresh token used unless it is used already; false when
     * another refresh came first.
     */
    public function claimRefreshToken(string $id, \DateTimeImmutable $now): bool;

    public function flush(): void;
}
