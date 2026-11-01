<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * What differs for one firm from the data the firms of the organisation share:
 * the contact's roles, what a new invoice starts with, and whether the contact
 * is archived for this firm. Null means the shared value applies; a row with
 * nothing left is removed. A party has at most one row per firm. A firm's own
 * invoicing e-mails are PartyEmail rows that name the firm.
 *
 * The invoicing values are plain nullable columns: Doctrine cannot hydrate a
 * missing embeddable as null. All of them are set, or none is.
 *
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

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $roles = null;

    #[ORM\Column(name: 'invoicing_due_days', type: 'integer', nullable: true)]
    private ?int $invoicingDueDays = null;

    #[ORM\Column(name: 'invoicing_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $invoicingCurrency = null;

    #[ORM\Column(name: 'invoicing_language', type: 'string', length: 2, nullable: true, enumType: DocumentLanguage::class, options: ['fixed' => true])]
    private ?DocumentLanguage $invoicingLanguage = null;

    #[ORM\Column(name: 'invoicing_payment_method', type: 'string', length: 32, nullable: true, enumType: PaymentMethod::class)]
    private ?PaymentMethod $invoicingPaymentMethod = null;

    #[ORM\Column(name: 'invoicing_reverse_charge', type: 'boolean', nullable: true)]
    private ?bool $invoicingReverseCharge = null;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $legalEntity,
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

    /**
     * @return list<PartyRole>|null null when the shared roles apply
     */
    public function roles(): ?array
    {
        return null === $this->roles ? null : array_map(PartyRole::from(...), $this->roles);
    }

    /**
     * @param list<PartyRole>|null $roles an empty list means no role for this firm
     */
    public function changeRoles(?array $roles): void
    {
        $this->roles = null === $roles ? null : array_values(array_unique(array_map(static fn (PartyRole $role): string => $role->value, $roles)));
    }

    public function invoicingDefaults(): ?InvoicingDefaults
    {
        if (null === $this->invoicingDueDays || !$this->invoicingCurrency instanceof Currency || !$this->invoicingLanguage instanceof DocumentLanguage
            || !$this->invoicingPaymentMethod instanceof PaymentMethod || null === $this->invoicingReverseCharge) {
            return null;
        }

        return new InvoicingDefaults($this->invoicingDueDays, $this->invoicingCurrency, $this->invoicingLanguage, $this->invoicingPaymentMethod, $this->invoicingReverseCharge);
    }

    public function changeInvoicingDefaults(?InvoicingDefaults $defaults): void
    {
        $this->invoicingDueDays = $defaults?->dueDays;
        $this->invoicingCurrency = $defaults?->currency;
        $this->invoicingLanguage = $defaults?->language;
        $this->invoicingPaymentMethod = $defaults?->paymentMethod;
        $this->invoicingReverseCharge = $defaults?->reverseCharge;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archive(\DateTimeImmutable $at): void
    {
        $this->archivedAt ??= $at;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function isEmpty(): bool
    {
        return null === $this->roles && null === $this->invoicingDueDays && !$this->archivedAt instanceof \DateTimeImmutable;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
