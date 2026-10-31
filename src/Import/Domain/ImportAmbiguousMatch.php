<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A record that a row of the source matched together with others. The row is an
 * error ("ambiguous"), but the records it names are in the file, so a full
 * replace never archives them as missing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_ambiguous_match')]
#[ORM\Index(name: 'idx_import_ambiguous_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_import_ambiguous_record', columns: ['import_id', 'record_id'])]
class ImportAmbiguousMatch implements TenantOwned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Import::class)]
        #[ORM\JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Import $import,
        #[ORM\Column(name: 'record_id', type: 'integer')]
        private readonly int $recordId,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function import(): Import
    {
        return $this->import;
    }

    public function recordId(): int
    {
        return $this->recordId;
    }
}
