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
 * A bank account of the issuing firm, where its customers pay. An IBAN is there
 * once per firm, archived accounts included, and the IBAN never changes. An
 * account is never deleted: invoices and bank movements point at it, so it is
 * archived instead. Among the active accounts at most one is the primary one,
 * which new invoices get; ManageFirmBankAccounts keeps it that way and, as a
 * backstop, a unique index over the firm and primary_flag does too (the flag is
 * true on the primary account and NULL on the others, see PartyAddress for why).
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_bank_account')]
#[ORM\Index(name: 'idx_firm_bank_account_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_firm_bank_account_iban', columns: ['legal_entity_id', 'iban'])]
#[ORM\UniqueConstraint(name: 'uniq_firm_bank_account_primary', columns: ['legal_entity_id', 'primary_flag'])]
class FirmBankAccount implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_LABEL_LENGTH = 100;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 34)]
    private string $iban;

    #[ORM\Column(type: 'string', length: self::MAX_LABEL_LENGTH)]
    private string $label;

    #[ORM\Column(name: 'primary_flag', type: 'boolean', nullable: true)]
    private ?bool $primaryFlag = null;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        Iban $iban,
        #[ORM\Column(type: 'string', length: 3, enumType: Currency::class)]
        private Currency $currency,
        string $label,
        #[ORM\Column(type: 'integer')]
        private int $position,
    ) {
        $this->id = Id::generate();
        $this->iban = $iban->toString();
        $this->label = $this->cleanLabel($label);
    }

    /**
     * A new account is not primary; ManageFirmBankAccounts decides that.
     *
     * @param int $position where the account comes in the firm's list, after the primary one
     */
    public static function open(LegalEntity $firm, Iban $iban, Currency $currency, string $label, int $position): self
    {
        return new self($firm, $iban, $currency, $label, $position);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->firm;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function iban(): string
    {
        return $this->iban;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isPrimary(): bool
    {
        return true === $this->primaryFlag;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function relabel(string $label): void
    {
        $this->label = $this->cleanLabel($label);
    }

    /**
     * Whether the currency may still change is for ManageFirmBankAccounts to say:
     * it is fixed once the account has bank movements.
     */
    public function changeCurrency(Currency $currency): void
    {
        $this->currency = $currency;
    }

    public function markPrimary(bool $primary): void
    {
        if ($primary && $this->isArchived()) {
            throw new \DomainException('An archived bank account cannot be the primary one.');
        }
        $this->primaryFlag = $primary ? true : null;
    }

    /**
     * Repeatable: the first date stays. An archived account is no longer primary.
     */
    public function archive(\DateTimeImmutable $at): void
    {
        $this->archivedAt ??= $at;
        $this->primaryFlag = null;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function cleanLabel(string $label): string
    {
        $label = trim($label);
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new \DomainException(\sprintf('A bank account label has at most %d characters.', self::MAX_LABEL_LENGTH));
        }

        return $label;
    }
}
