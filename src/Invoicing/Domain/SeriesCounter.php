<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last number given in one period of a series. Mapped so the schema is
 * validated and the tenant filter applies to queries, but only the single atomic
 * statements of the DoctrineNumberCounter write it and the ORM never loads a row.
 * A technical value, so not audited: the audited Series and documents explain it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'series_counter')]
#[ORM\Index(name: 'idx_series_counter_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_series_counter', columns: ['series_id', 'period'])]
class SeriesCounter implements TenantOwned
{
    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: Series::class)]
        #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'RESTRICT')]
        public readonly Series $series,
        #[ORM\Column(type: 'string', length: 8)]
        public readonly string $period,
        #[ORM\Column(name: 'last_value', type: 'integer')]
        public readonly int $lastValue,
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
