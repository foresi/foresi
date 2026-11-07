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
 * One pull, check or run of the move from iDoklad, asked for on a screen and
 * done by a worker: whether it waits, runs or ended, why it failed, and what it
 * made (the pull or the import run). A run carries the day from which reminders
 * go out for the invoices it brings, and the options the person chose for it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_job')]
#[ORM\Index(name: 'idx_idoklad_job_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_idoklad_job_firm', columns: ['legal_entity_id', 'requested_at'])]
class IdokladJob implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 8, enumType: IdokladJobState::class)]
    private IdokladJobState $state = IdokladJobState::Pending;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** The pull or the import run the job made. */
    #[ORM\Column(name: 'result_id', type: 'guid', nullable: true)]
    private ?string $resultId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $error = null;

    /** @var array<mixed>|null the options of a run; null for the defaults */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $options;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 8, enumType: IdokladJobKind::class)]
        private readonly IdokladJobKind $kind,
        #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $requestedAt,
        #[ORM\Column(name: 'remind_from', type: 'date_immutable', nullable: true)]
        private readonly ?\DateTimeImmutable $remindFrom = null,
        ?ImportOptions $options = null,
    ) {
        if ($remindFrom instanceof \DateTimeImmutable && IdokladJobKind::Run !== $kind) {
            throw new \InvalidArgumentException('Only a run sets the day reminders start from.');
        }
        if ($options instanceof ImportOptions && IdokladJobKind::Run !== $kind) {
            throw new \InvalidArgumentException('Only a run is given options.');
        }
        $this->options = $options?->toArray();
        $this->id = Id::generate();
    }

    public function start(\DateTimeImmutable $at): void
    {
        $this->state = IdokladJobState::Running;
        $this->startedAt = $at;
    }

    public function finish(\DateTimeImmutable $at, ?string $resultId, ?string $error = null): void
    {
        $this->state = null === $error ? IdokladJobState::Done : IdokladJobState::Failed;
        $this->finishedAt = $at;
        $this->resultId = $resultId;
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

    public function kind(): IdokladJobKind
    {
        return $this->kind;
    }

    public function state(): IdokladJobState
    {
        return $this->state;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function remindFrom(): ?\DateTimeImmutable
    {
        return $this->remindFrom;
    }

    /** The options the person chose for a run; null for the defaults of the snapshot. */
    public function options(): ?ImportOptions
    {
        return null === $this->options ? null : ImportOptions::fromArray($this->options);
    }

    public function resultId(): ?string
    {
        return $this->resultId;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
