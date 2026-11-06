<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One copy of a firm's iDoklad account into the snapshot: when it ran, how
 * many records each list gave, how many API requests it used and why it
 * stopped, if it did. A delta pull asks only for what changed after a moment
 * in iDoklad's own time, which an earlier pull recorded as its latest change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_pull')]
#[ORM\Index(name: 'idx_idoklad_pull_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_idoklad_pull_firm', columns: ['legal_entity_id', 'started_at'])]
class IdokladPull implements TenantOwned
{
    /** A moment as iDoklad writes it, without a zone: 2026-10-26T10:39:22.873. */
    public const string MOMENT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,7})?$/D';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var array<string, int> records by resource */
    #[ORM\Column(type: 'json')]
    private array $counts = [];

    #[ORM\Column(name: 'requests_used', type: 'integer')]
    private int $requestsUsed = 0;

    /** The latest change among the records pulled, in iDoklad's own time. */
    #[ORM\Column(name: 'latest_change', type: 'string', length: 32, nullable: true)]
    private ?string $latestChange = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'changed_since', type: 'string', length: 32, nullable: true)]
        private readonly ?string $changedSince = null,
    ) {
        if (null !== $changedSince && 1 !== preg_match(self::MOMENT, $changedSince)) {
            throw new \InvalidArgumentException('A delta pull starts at a moment as iDoklad writes it.');
        }
        $this->id = Id::generate();
    }

    /**
     * Records how many records a list gave.
     */
    public function counted(IdokladResource $resource, int $records): void
    {
        $this->counts[$resource->value] = $records;
    }

    /**
     * Keeps the latest of the changes seen. Moments as iDoklad writes them
     * compare as text.
     */
    public function noteChange(?string $change): void
    {
        if (null !== $change && strcmp($change, $this->latestChange ?? '') > 0) {
            $this->latestChange = $change;
        }
    }

    public function finish(\DateTimeImmutable $at, int $requestsUsed, ?string $error = null): void
    {
        $this->finishedAt = $at;
        $this->requestsUsed = $requestsUsed;
        $this->error = null === $error ? null : mb_substr($error, 0, 255);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function changedSince(): ?string
    {
        return $this->changedSince;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    public function requestsUsed(): int
    {
        return $this->requestsUsed;
    }

    public function latestChange(): ?string
    {
        return $this->latestChange;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function succeeded(): bool
    {
        return $this->finishedAt instanceof \DateTimeImmutable && null === $this->error;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
