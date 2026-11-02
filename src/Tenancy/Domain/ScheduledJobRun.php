<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The state of one scheduled job in one tenant: the last period that finished,
 * the run in progress and the error of the last failed run. Mapped so the schema
 * is validated and the tenant filter applies, but only the atomic statements of
 * the DoctrineScheduledJobRuns write it and the ORM never loads a row. A
 * technical value, so not audited, like the series counter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scheduled_job_run')]
#[ORM\Index(name: 'idx_scheduled_job_run_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_scheduled_job_run', columns: ['tenant_id', 'job'])]
class ScheduledJobRun implements TenantOwned
{
    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $job,
        #[ORM\Column(name: 'last_period', type: 'string', length: 32, nullable: true)]
        public readonly ?string $lastPeriod = null,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $startedAt = null,
        #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $finishedAt = null,
        #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
        public readonly ?string $lastError = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
