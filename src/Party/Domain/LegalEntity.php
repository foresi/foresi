<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * The issuing firm of the instance. An instance holds one (ADR 0039), created
 * by the first-run setup, see InstanceFirm. Its bank accounts are records of
 * their own, see FirmBankAccount.
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_entity')]
class LegalEntity implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    // The name of the stored image, see FirmImages. The stamp is one image holding stamp and signature.
    #[ORM\Column(type: 'string', length: 72, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: 'string', length: 72, nullable: true)]
    private ?string $stamp = null;

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
    ) {
        $this->id = Id::generate();
        $this->name = $this->cleanName($name);
    }

    public function update(string $name, RegistrationNumber $registrationNumber, ?VatId $vatId, Address $address, VatStatus $vatStatus, string $registerEntry): void
    {
        $this->name = $this->cleanName($name);
        $this->registrationNumber = $registrationNumber;
        $this->vatId = $vatId;
        $this->address = $address;
        $this->vatStatus = $vatStatus;
        $this->registerEntry = $registerEntry;
    }

    public function id(): string
    {
        return $this->id;
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

    public function logo(): ?string
    {
        return $this->logo;
    }

    public function changeLogo(?string $name): void
    {
        $this->logo = $name;
    }

    public function stamp(): ?string
    {
        return $this->stamp;
    }

    public function changeStamp(?string $name): void
    {
        $this->stamp = $name;
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('A legal entity needs a name.');
        }

        return $name;
    }
}
