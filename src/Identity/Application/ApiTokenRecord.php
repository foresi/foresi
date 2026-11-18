<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenState;

/**
 * A personal API token as read to sign a request in, and for the operator's
 * list. Never holds
 * the secret or its hash.
 */
final readonly class ApiTokenRecord
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $name,
        public array $scopes,
        public string $lastFour,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public ?\DateTimeImmutable $revokedAt,
        public ?string $oauthClientId = null,
    ) {
    }

    public function state(\DateTimeImmutable $now): ApiTokenState
    {
        return ApiTokenState::of($this->revokedAt, $this->expiresAt, $now);
    }
}
