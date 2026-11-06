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
 * One import of a firm's iDoklad snapshot into Foresi, checked or run: when,
 * from which pull, the outcomes counted by stage, the report record by record
 * and why it stopped, if it did. A check is kept too, though what it did was
 * rolled back, so its report can be read before the run.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_import_run')]
#[ORM\Index(name: 'idx_idoklad_import_run_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_idoklad_import_run_firm', columns: ['legal_entity_id', 'started_at'])]
class IdokladImportRun implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'pull_id', type: 'guid', nullable: true)]
    private ?string $pullId = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var array<string, array<string, int>> outcomes by stage */
    #[ORM\Column(type: 'json')]
    private array $counts = [];

    /** @var list<array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}> */
    #[ORM\Column(type: 'json')]
    private array $report = [];

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $error = null;

    /** When what the run brought was taken back. */
    #[ORM\Column(name: 'undone_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 8, enumType: ImportMode::class)]
        private readonly ImportMode $mode,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $startedAt,
        ?string $id = null,
    ) {
        $this->id = $id ?? Id::generate();
    }

    public function readFrom(IdokladPull $pull): void
    {
        $this->pullId = $pull->id();
    }

    public function finish(\DateTimeImmutable $at, ImportReport $report, ?string $error = null): void
    {
        $this->finishedAt = $at;
        $this->counts = $report->counts();
        $this->report = $report->entries();
        $this->error = null === $error ? null : mb_substr($error, 0, 255);
    }

    public function markUndone(\DateTimeImmutable $at): void
    {
        $this->undoneAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function mode(): ImportMode
    {
        return $this->mode;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function pullId(): ?string
    {
        return $this->pullId;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return list<array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}>
     */
    public function report(): array
    {
        return $this->report;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function undoneAt(): ?\DateTimeImmutable
    {
        return $this->undoneAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
