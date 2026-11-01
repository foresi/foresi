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
 * doing the work and the one that invoices. Among the active firms of a tenant a
 * registration number is unique; an archived firm stays for what it issued and
 * releases its number. Production code goes through the firm screens, which turn
 * a taken number into a form error before the unique index would refuse it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_entity')]
#[ORM\Index(name: 'idx_legal_entity_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_legal_entity_active_registration', columns: ['tenant_id', 'registration_country', 'active_registration_key'])]
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

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    // The comparison form of the registration number while the firm is active, null once it is
    // archived. Unique indexes ignore nulls on every database, which a partial index cannot
    // promise: Doctrine cannot read one back from SQLite, so schema validation would fail there.
    #[ORM\Column(name: 'active_registration_key', type: 'string', length: 192, nullable: true)]
    private ?string $activeRegistrationKey;

    // The name of the stored image, see FirmImages. The stamp is one image holding stamp and signature.
    #[ORM\Column(type: 'string', length: 72, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: 'string', length: 72, nullable: true)]
    private ?string $stamp = null;

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
        $this->name = $this->cleanName($name);
        $this->bankAccounts = $this->cleanAccounts($bankAccounts);
        $this->activeRegistrationKey = $registrationNumber->normalised();
    }

    /**
     * @param array<string> $bankAccounts IBANs (keys are discarded); spaces and case are normalised
     */
    public function update(string $name, RegistrationNumber $registrationNumber, ?VatId $vatId, Address $address, VatStatus $vatStatus, string $registerEntry, array $bankAccounts): void
    {
        $this->name = $this->cleanName($name);
        $this->bankAccounts = $this->cleanAccounts($bankAccounts);
        $this->registrationNumber = $registrationNumber;
        $this->vatId = $vatId;
        $this->address = $address;
        $this->vatStatus = $vatStatus;
        $this->registerEntry = $registerEntry;
        if (!$this->isArchived()) {
            $this->activeRegistrationKey = $registrationNumber->normalised();
        }
    }

    /**
     * Repeatable: the first date stays.
     */
    public function archive(\DateTimeImmutable $at): void
    {
        $this->archivedAt ??= $at;
        $this->activeRegistrationKey = null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Whether this firm, being active, has that number, however it is written.
     */
    public function holdsRegistrationNumber(RegistrationNumber $number): bool
    {
        return $number->countryCode === $this->registrationNumber->countryCode && $number->normalised() === $this->activeRegistrationKey;
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

    /**
     * @return list<string>
     */
    public function bankAccounts(): array
    {
        return $this->bankAccounts;
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

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('A legal entity needs a name.');
        }

        return $name;
    }

    /**
     * @param array<string> $bankAccounts
     *
     * @return list<string>
     */
    private function cleanAccounts(array $bankAccounts): array
    {
        return array_values(array_map(static fn (string $iban): string => Iban::fromString($iban)->toString(), $bankAccounts));
    }
}
