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
 * Connects a contact person to a company or sole trader. A person can work
 * for several companies and a company has several contacts. A pair is linked
 * once (ContactLinks, plus a unique index).
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact_link')]
#[ORM\Index(name: 'idx_contact_link_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_contact_link_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_contact_link_person', columns: ['person_id'])]
#[ORM\UniqueConstraint(name: 'uniq_contact_link_pair', columns: ['tenant_id', 'company_id', 'person_id'])]
class ContactLink implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $position;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private readonly Party $company,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'person_id', nullable: false)]
        private readonly Party $person,
        string $position = '',
        #[ORM\Column(name: 'is_primary', type: 'boolean')]
        private bool $primary = false,
    ) {
        $this->id = Id::generate();
        if (PartyKind::Person === $company->kind()) {
            throw new \DomainException('A contact person is linked to a company or sole trader, not to a person.');
        }
        if (PartyKind::Person !== $person->kind()) {
            throw new \DomainException('Only a person can be a contact person.');
        }

        $companyTenant = $company->stampedTenantId();
        $personTenant = $person->stampedTenantId();
        if ($companyTenant instanceof TenantId && $personTenant instanceof TenantId && !$companyTenant->equals($personTenant)) {
            throw new \DomainException('A contact can only be linked within one tenant.');
        }
        $this->tenantId = $companyTenant ?? $personTenant;
        $this->position = trim($position);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->company;
    }

    public function company(): Party
    {
        return $this->company;
    }

    public function person(): Party
    {
        return $this->person;
    }

    public function position(): string
    {
        return $this->position;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function changePosition(string $position): void
    {
        $this->position = trim($position);
    }

    public function markPrimary(bool $primary): void
    {
        $this->primary = $primary;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
