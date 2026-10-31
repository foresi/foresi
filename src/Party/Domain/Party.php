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
 * A person or organisation the tenant does business with: a customer, a
 * supplier, a partner, an authority or just a contact. Addresses, e-mails,
 * bank accounts and contact links are separate audited entities pointing here.
 *
 * Production code creates parties through CreateParty (Party Application),
 * which also creates the billing address and the first e-mail address.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party')]
#[ORM\Index(name: 'idx_party_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_party_registration_key', columns: ['tenant_id', 'registration_key'])]
#[ORM\Index(name: 'idx_party_vat_id', columns: ['tenant_id', 'vat_id'])]
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

    // The comparison form of the registration number, so duplicates are found in SQL.
    #[ORM\Column(name: 'registration_key', type: 'string', length: 192, nullable: true)]
    private ?string $registrationKey;

    #[ORM\Column(name: 'vat_id', type: 'vat_id', length: 14, nullable: true)]
    private ?VatId $vatId;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'private_note', type: 'text')]
    private string $privateNote = '';

    #[ORM\Column(type: 'string', length: 64, options: ['default' => ''])]
    private string $mobile = '';

    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $website = '';

    #[ORM\Embedded(class: InvoicingDefaults::class, columnPrefix: 'invoicing_')]
    private InvoicingDefaults $invoicingDefaults;

    // The registry checks are plain nullable columns, like the registration number: the source is
    // implied by the column pair, and Doctrine cannot hydrate a never-checked embeddable as null.
    #[ORM\Column(name: 'vies_result', type: 'string', length: 32, nullable: true, enumType: RegistryResult::class)]
    private ?RegistryResult $viesResult = null;

    #[ORM\Column(name: 'vies_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $viesCheckedAt = null;

    #[ORM\Column(name: 'unreliable_payer_result', type: 'string', length: 32, nullable: true, enumType: RegistryResult::class)]
    private ?RegistryResult $unreliablePayerResult = null;

    #[ORM\Column(name: 'unreliable_payer_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $unreliablePayerCheckedAt = null;

    /** @var list<string>|null the bank accounts published with the last unreliable payer check */
    #[ORM\Column(name: 'unreliable_payer_accounts', type: 'json', nullable: true)]
    private ?array $unreliablePayerAccounts = null;

    /**
     * @param list<PartyRole> $roles
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 32, enumType: PartyKind::class)]
        private PartyKind $kind,
        string $displayName,
        ?RegistrationNumber $registrationNumber,
        ?VatId $vatId,
        #[ORM\Column(type: 'string', length: 64)]
        private string $phone,
        array $roles = [],
    ) {
        $this->rename($displayName);
        $this->setIdentifiers($registrationNumber, $vatId);
        $this->assignRoles($roles);
        $this->invoicingDefaults = InvoicingDefaults::standard();
    }

    public function id(): ?int
    {
        return $this->id;
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

    public function phone(): string
    {
        return $this->phone;
    }

    public function mobile(): string
    {
        return $this->mobile;
    }

    public function website(): string
    {
        return $this->website;
    }

    /**
     * @return list<PartyRole>
     */
    public function roles(): array
    {
        return array_map(PartyRole::from(...), $this->roles);
    }

    public function hasRole(PartyRole $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function privateNote(): string
    {
        return $this->privateNote;
    }

    public function invoicingDefaults(): InvoicingDefaults
    {
        return $this->invoicingDefaults;
    }

    public function viesCheck(): ?RegistryCheck
    {
        return $this->check(RegistrySource::Vies, $this->viesResult, $this->viesCheckedAt);
    }

    public function unreliablePayerCheck(): ?RegistryCheck
    {
        return $this->check(RegistrySource::UnreliablePayer, $this->unreliablePayerResult, $this->unreliablePayerCheckedAt);
    }

    /**
     * @return list<string> account numbers or IBANs as the register publishes them
     */
    public function publishedBankAccounts(): array
    {
        return $this->unreliablePayerAccounts ?? [];
    }

    public function rename(string $displayName): void
    {
        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \DomainException('A party needs a display name.');
        }
        $this->displayName = $displayName;
    }

    public function changePhone(string $phone): void
    {
        $this->phone = $phone;
    }

    public function changeMobile(string $mobile): void
    {
        $this->mobile = trim($mobile);
    }

    public function changeWebsite(string $website): void
    {
        $this->website = trim($website);
    }

    public function changeIdentifiers(?RegistrationNumber $registrationNumber, ?VatId $vatId): void
    {
        $vatIdChanged = $this->vatId?->toString() !== $vatId?->toString();
        $this->setIdentifiers($registrationNumber, $vatId);
        if ($vatIdChanged) {
            // The verdicts and the published accounts belong to the VAT id they were fetched for.
            $this->clearRegistryChecks();
        }
    }

    private function setIdentifiers(?RegistrationNumber $registrationNumber, ?VatId $vatId): void
    {
        if (PartyKind::Company === $this->kind && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId) {
            throw new \DomainException('A company needs a registration number or a VAT id.');
        }
        $this->registrationCountry = $registrationNumber?->countryCode;
        $this->registrationNumber = $registrationNumber?->value;
        $this->registrationKey = $registrationNumber?->normalised();
        $this->vatId = $vatId;
    }

    /**
     * Replaces the roles; duplicates are dropped and the order is kept.
     *
     * @param list<PartyRole> $roles
     */
    public function assignRoles(array $roles): void
    {
        $this->roles = array_values(array_unique(array_map(static fn (PartyRole $role): string => $role->value, $roles)));
    }

    public function changePrivateNote(string $note): void
    {
        $this->privateNote = $note;
    }

    public function changeInvoicingDefaults(InvoicingDefaults $defaults): void
    {
        $this->invoicingDefaults = $defaults;
    }

    /**
     * Archived parties stay in the data and remain referenceable; lists hide them by default.
     */
    public function archive(\DateTimeImmutable $at): void
    {
        $this->archivedAt ??= $at;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    private function clearRegistryChecks(): void
    {
        $this->viesResult = null;
        $this->viesCheckedAt = null;
        $this->unreliablePayerResult = null;
        $this->unreliablePayerCheckedAt = null;
        $this->unreliablePayerAccounts = null;
    }

    public function recordRegistryCheck(RegistryCheck $check): void
    {
        if (RegistrySource::Vies === $check->source) {
            $this->viesResult = $check->result;
            $this->viesCheckedAt = $check->checkedAt;
        } else {
            $this->unreliablePayerResult = $check->result;
            $this->unreliablePayerCheckedAt = $check->checkedAt;
        }
    }

    /**
     * @param list<string> $accounts
     */
    public function recordPublishedBankAccounts(array $accounts): void
    {
        $this->unreliablePayerAccounts = $accounts;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    /**
     * The tenant if the party was persisted already; children created for it
     * adopt it so that a party of another tenant is rejected on persist.
     */
    public function stampedTenantId(): ?TenantId
    {
        return $this->tenantId;
    }

    private function check(RegistrySource $source, ?RegistryResult $result, ?\DateTimeImmutable $checkedAt): ?RegistryCheck
    {
        if (!$result instanceof RegistryResult || !$checkedAt instanceof \DateTimeImmutable) {
            return null;
        }

        return new RegistryCheck($source, $result, $checkedAt);
    }
}
