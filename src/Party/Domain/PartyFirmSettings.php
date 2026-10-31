<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a new invoice from one firm to or from this party starts with, where it
 * differs from the party's own defaults. A party has at most one row per firm.
 * Firms are archived, never deleted, and a restricting foreign key backs that
 * up: a firm with settings cannot be removed from the database.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_firm_settings')]
#[ORM\Index(name: 'idx_party_firm_settings_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_party_firm_settings_party', columns: ['party_id'])]
#[ORM\Index(name: 'idx_party_firm_settings_firm', columns: ['legal_entity_id'])]
#[ORM\UniqueConstraint(name: 'uniq_party_firm_settings', columns: ['tenant_id', 'party_id', 'legal_entity_id'])]
class PartyFirmSettings implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $legalEntity,
        #[ORM\Embedded(class: InvoicingDefaults::class, columnPrefix: 'invoicing_')]
        private InvoicingDefaults $invoicingDefaults,
    ) {
        $this->id = Id::generate();
        $this->tenantId = $party->stampedTenantId();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->party;
    }

    public function party(): Party
    {
        return $this->party;
    }

    public function legalEntity(): LegalEntity
    {
        return $this->legalEntity;
    }

    public function invoicingDefaults(): InvoicingDefaults
    {
        return $this->invoicingDefaults;
    }

    public function change(InvoicingDefaults $defaults): void
    {
        $this->invoicingDefaults = $defaults;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
