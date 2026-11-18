<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Shared\Domain\Id;

/**
 * A personal API token: a person's access to one organisation from another
 * program, with what it may do and until when. A token linked to an OAuth
 * client is that client's connection (ADR 0036): a refresh puts a new secret
 * into the same row. Only the SHA-256 of its secret
 * is kept, and the last four characters to tell tokens apart. The hash and the
 * time of last use are never written to the audit log.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_token')]
#[ORM\Index(name: 'idx_api_token_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_api_token_hash', columns: ['token_hash'])]
class ApiToken implements Audited
{
    public const int NAME_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[AuditExcluded]
    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'revoked_by', type: 'guid', nullable: true)]
    private ?string $revokedBy = null;

    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'guid')]
        private string $userId,
        string $name,
        array $scopes,
        #[AuditExcluded]
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'last_four', type: 'string', length: 4)]
        private string $lastFour,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,
        // The OAuth client this token connects (ADR 0036); null for a personal token issued by hand.
        #[ORM\Column(name: 'oauth_client_id', type: 'guid', nullable: true)]
        private ?string $oauthClientId = null,
    ) {
        if ('' === $userId) {
            throw new \InvalidArgumentException('An API token belongs to a user.');
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An API token has a name of 1 to %d characters.', self::NAME_MAX_LENGTH));
        }
        if ([] === $scopes) {
            throw new \InvalidArgumentException('An API token has at least one scope.');
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('An API token keeps the SHA-256 of its secret.');
        }
        if (4 !== \strlen($lastFour)) {
            throw new \InvalidArgumentException('An API token keeps the last four characters of its secret.');
        }
        if ($expiresAt <= $createdAt) {
            throw new \InvalidArgumentException('An API token expires after it is created.');
        }
        $this->id = Id::generate();
        $this->name = $name;
        $this->scopes = array_values(array_unique(array_map(static fn (ApiScope $scope): string => $scope->value, $scopes)));
    }

    public function state(\DateTimeImmutable $now): ApiTokenState
    {
        return ApiTokenState::of($this->revokedAt, $this->expiresAt, $now);
    }

    /**
     * Revoking twice keeps the first time and the first person. Null is the operator.
     */
    public function revoke(?string $by, \DateTimeImmutable $now): void
    {
        if ($this->revokedAt instanceof \DateTimeImmutable) {
            return;
        }
        $this->revokedAt = $now;
        $this->revokedBy = $by;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        return array_values(array_filter(array_map(ApiScope::tryFrom(...), $this->scopes)));
    }

    public function lastFour(): string
    {
        return $this->lastFour;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function oauthClientId(): ?string
    {
        return $this->oauthClientId;
    }

    public function revokedBy(): ?string
    {
        return $this->revokedBy;
    }
}
