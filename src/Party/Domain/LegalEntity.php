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
 * The issuing firm of a tenant. A tenant can have several, for example the one
 * doing the work and the one that invoices.
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_entity')]
#[ORM\Index(name: 'idx_legal_entity_tenant', columns: ['tenant_id'])]
class LegalEntity implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(name: 'bank_accounts', type: 'json')]
    private array $bankAccounts;

    /**
     * @param array<string> $bankAccounts IBANs (keys are discarded); spaces and case are normalised
     */
    public function __construct(
        string $name,
        #[ORM\Embedded(class: RegistrationNumber::class, columnPrefix: false)]
        private RegistrationNumber $registrationNumber,
        #[ORM\Column(name: 'vat_id', type: 'vat_id', length: 14, nullable: true)]
        private ?VatId $vatId,
        #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
        private Address $address,
        #[ORM\Column(name: 'vat_status', type: 'string', length: 32, enumType: VatStatus::class)]
        private VatStatus $vatStatus,
        #[ORM\Column(name: 'register_entry', type: 'string', length: 255)]
        private string $registerEntry,
        array $bankAccounts,
    ) {
        $this->id = Id::generate();
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('A legal entity needs a name.');
        }
        $this->name = $name;
        $this->bankAccounts = array_values(array_map(static fn (string $iban): string => Iban::fromString($iban)->toString(), $bankAccounts));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function registrationNumber(): RegistrationNumber
    {
        return $this->registrationNumber;
    }

    public function vatId(): ?VatId
    {
        return $this->vatId;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function vatStatus(): VatStatus
    {
        return $this->vatStatus;
    }

    public function registerEntry(): string
    {
        return $this->registerEntry;
    }

    /**
     * @return list<string>
     */
    public function bankAccounts(): array
    {
        return $this->bankAccounts;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
