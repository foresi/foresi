<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Identity\Domain\ApiScope;

/**
 * The answer of the token endpoint (RFC 6749, 5.1). Holds two secrets; it is
 * sent once and never kept.
 */
final readonly class TokenResponse
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public int $expiresIn,
        #[\SensitiveParameter]
        public string $refreshToken,
        public array $scopes,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => implode(' ', array_map(static fn (ApiScope $scope): string => $scope->value, $this->scopes)),
        ];
    }
}
