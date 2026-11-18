<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * The refresh token of an OAuth connection (ADR 0036): it renews the access
 * token of one API token row, once, and is replaced by a new one each time.
 * Only the SHA-256 is kept. A used one is kept too, so that presenting it again
 * is recognised as theft and ends the connection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_refresh_token')]
#[ORM\Index(name: 'idx_oauth_refresh_token_api_token', columns: ['api_token_id'])]
#[ORM\UniqueConstraint(name: 'uniq_oauth_refresh_token_hash', columns: ['token_hash'])]
class RefreshToken
{
    public const string PREFIX = 'foresi_ort_';
    public const int LIFETIME_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'api_token_id', type: 'guid')]
        private string $apiTokenId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('A refresh token keeps the SHA-256 of its secret.');
        }
        $this->id = Id::generate();
        $this->expiresAt = $createdAt->modify(\sprintf('+%d days', self::LIFETIME_DAYS));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function apiTokenId(): string
    {
        return $this->apiTokenId;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt instanceof \DateTimeImmutable;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
