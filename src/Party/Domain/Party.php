<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Audit\Domain\Audited;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer and/or supplier of the tenant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party')]
#[ORM\Index(name: 'idx_party_tenant', columns: ['tenant_id'])]
class Party implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    // Stored as plain nullable columns: Doctrine cannot hydrate a missing embeddable as null.
    #[ORM\Column(name: 'registration_country', type: 'string', length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $registrationCountry;

    #[ORM\Column(name: 'registration_number', type: 'string', length: 64, nullable: true)]
    private ?string $registrationNumber;

    public function __construct(
        #[ORM\Column(type: 'string', length: 32, enumType: PartyKind::class)]
        private PartyKind $kind,
        string $displayName,
        ?RegistrationNumber $registrationNumber,
        #[ORM\Column(name: 'vat_id', type: 'vat_id', length: 14, nullable: true)]
        private ?VatId $vatId,
        #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
        private Address $address,
        #[ORM\Column(type: 'string', length: 255)]
        private string $email,
        #[ORM\Column(type: 'string', length: 64)]
        private string $phone,
        #[ORM\Column(name: 'is_customer', type: 'boolean')]
        private bool $isCustomer,
        #[ORM\Column(name: 'is_supplier', type: 'boolean')]
        private bool $isSupplier,
    ) {
        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \DomainException('A party needs a display name.');
        }
        if (PartyKind::Company === $kind && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId) {
            throw new \DomainException('A company needs a registration number or a VAT id.');
        }
        $this->displayName = $displayName;
        $this->registrationCountry = $registrationNumber?->countryCode;
        $this->registrationNumber = $registrationNumber?->value;
    }

    public function kind(): PartyKind
    {
        return $this->kind;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function registrationNumber(): ?RegistrationNumber
    {
        if (null === $this->registrationCountry || null === $this->registrationNumber) {
            return null;
        }

        return new RegistrationNumber($this->registrationCountry, $this->registrationNumber);
    }

    public function vatId(): ?VatId
    {
        return $this->vatId;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function isCustomer(): bool
    {
        return $this->isCustomer;
    }

    public function isSupplier(): bool
    {
        return $this->isSupplier;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
