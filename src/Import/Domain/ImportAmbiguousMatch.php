<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Shared\Domain\Id;
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
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Import::class)]
        #[ORM\JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Import $import,
        #[ORM\Column(name: 'record_id', type: 'guid')]
        private readonly string $recordId,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
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

    public function recordId(): string
    {
        return $this->recordId;
    }
}
