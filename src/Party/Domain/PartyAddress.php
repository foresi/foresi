<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Audit\Domain\Audited;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address of a party. Every party has exactly one billing address: CreateParty
 * creates the party together with it, PartyAddresses refuses a second one, and a
 * unique index over the party and billing_flag backs that up. The flag is true on
 * the billing address and NULL on all others; NULLs never collide in a unique
 * index, on SQLite as on PostgreSQL, which a partial index would not give both.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_address')]
#[ORM\Index(name: 'idx_party_address_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_party_address_party', columns: ['party_id'])]
#[ORM\UniqueConstraint(name: 'uniq_party_address_billing', columns: ['party_id', 'billing_flag'])]
class PartyAddress implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    #[ORM\Column(name: 'billing_flag', type: 'boolean', nullable: true)]
    private ?bool $billingFlag;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        #[ORM\Column(type: 'string', length: 16, enumType: AddressType::class)]
        private readonly AddressType $type,
        #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
        private Address $address,
        string $label = '',
    ) {
        $this->id = Id::generate();
        $this->tenantId = $party->stampedTenantId();
        $this->label = trim($label);
        $this->billingFlag = AddressType::Billing === $type ? true : null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function party(): Party
    {
        return $this->party;
    }

    public function type(): AddressType
    {
        return $this->type;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function relocate(Address $address): void
    {
        $this->address = $address;
    }

    public function relabel(string $label): void
    {
        $this->label = trim($label);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
