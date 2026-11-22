<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Shared\Domain\Id;

/**
 * A secret address a person gives their calendar app to see what Foresi plans
 * for them. A person may have several, one per app or device, and revokes one
 * without touching the others. The address is shown once; the feed keeps only
 * the SHA-256 of its token. A revoked feed stays listed, its address dead.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_feed')]
#[ORM\Index(name: 'idx_calendar_feed_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_token_hash', columns: ['token_hash'])]
class CalendarFeed implements Audited
{
    public const int NAME_MAX_LENGTH = 80;

    /** A fetch is recorded at most once in this many seconds, so polling writes little. */
    private const int FETCH_RECORDED_EVERY = 3600;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[AuditExcluded]
    #[ORM\Column(name: 'last_fetched_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'guid')]
        private string $userId,
        string $name,
        #[AuditExcluded]
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if ('' === $userId) {
            throw new \InvalidArgumentException('A calendar feed belongs to a person.');
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('A calendar feed keeps the SHA-256 of its token.');
        }
        $this->id = Id::generate();
        $this->name = self::validName($name);
    }

    public static function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A calendar feed has a name of 1 to %d characters.', self::NAME_MAX_LENGTH));
        }

        return $name;
    }

    public function rename(string $name): void
    {
        $this->name = self::validName($name);
    }

    /**
     * Revoking twice keeps the first time.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    /**
     * Records a fetch, unless one was recorded less than an hour ago.
     *
     * @return bool whether the fetch was recorded
     */
    public function fetched(\DateTimeImmutable $now): bool
    {
        if ($this->lastFetchedAt instanceof \DateTimeImmutable && $now->getTimestamp() - $this->lastFetchedAt->getTimestamp() < self::FETCH_RECORDED_EVERY) {
            return false;
        }
        $this->lastFetchedAt = $now;

        return true;
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

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof \DateTimeImmutable;
    }
}
