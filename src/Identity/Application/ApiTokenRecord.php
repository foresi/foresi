<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiTokenState;
use App\Tenancy\Domain\TenantId;

/**
 * A personal API token as read across organisations: to sign a request in
 * before any organisation is active, and for the operator's list. Never holds
 * the secret or its hash.
 */
final readonly class ApiTokenRecord
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public string $id,
        public TenantId $tenantId,
        public string $userId,
        public string $name,
        public array $scopes,
        public string $lastFour,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    public function state(\DateTimeImmutable $now): ApiTokenState
    {
        return ApiTokenState::of($this->revokedAt, $this->expiresAt, $now);
    }
}
