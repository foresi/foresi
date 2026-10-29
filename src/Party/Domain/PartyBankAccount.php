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
 * A bank account of a party. At most one account per party is the primary
 * one; PartyBankAccounts keeps it that way.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_bank_account')]
#[ORM\Index(name: 'idx_party_bank_account_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_party_bank_account_party', columns: ['party_id'])]
class PartyBankAccount implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId;

    #[ORM\Column(type: 'string', length: 34)]
    private string $iban;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $bic;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        Iban $iban,
        ?string $bic = null,
        string $label = '',
        #[ORM\Column(name: 'is_primary', type: 'boolean')]
        private bool $primary = false,
    ) {
        $this->tenantId = $party->stampedTenantId();
        $this->iban = $iban->toString();
        $this->bic = $this->validateBic($bic);
        $this->label = trim($label);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function party(): Party
    {
        return $this->party;
    }

    public function iban(): Iban
    {
        return Iban::fromString($this->iban);
    }

    public function bic(): ?string
    {
        return $this->bic;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function makePrimary(): void
    {
        $this->primary = true;
    }

    public function demote(): void
    {
        $this->primary = false;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function validateBic(?string $bic): ?string
    {
        if (null === $bic) {
            return null;
        }
        $bic = strtoupper((string) preg_replace('/\s+/', '', $bic));
        if (1 !== preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            throw new \DomainException('A BIC has 8 or 11 characters.');
        }

        return $bic;
    }
}
