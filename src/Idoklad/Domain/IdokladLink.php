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
 * What one iDoklad record became in Foresi: the id of the contact, series or
 * document an import created or matched for it. Only this module knows the
 * iDoklad ids, so the records they became stay free of them. A record is
 * linked once; a later run finds the link and leaves the record alone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_link')]
#[ORM\Index(name: 'idx_idoklad_link_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_idoklad_link', columns: ['legal_entity_id', 'resource', 'external_id'])]
class IdokladLink implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 32, enumType: IdokladResource::class)]
        private readonly IdokladResource $resource,
        #[ORM\Column(name: 'external_id', type: 'integer')]
        private readonly int $externalId,
        /** The id of the Foresi record; its kind follows from the resource. */
        #[ORM\Column(name: 'target_id', type: 'guid')]
        private readonly string $targetId,
        #[ORM\Column(name: 'run_id', type: 'guid')]
        private readonly string $runId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function resource(): IdokladResource
    {
        return $this->resource;
    }

    public function externalId(): int
    {
        return $this->externalId;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
