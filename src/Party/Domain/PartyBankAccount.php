<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * A bank account of a party. At most one account per party is the primary
 * one; PartyBankAccounts keeps it that way and, as a backstop, a unique index
 * over the party and primary_flag does too (the flag is true on the primary
 * account and NULL on the others, see PartyAddress for why).
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_bank_account')]
#[ORM\Index(name: 'idx_party_bank_account_party', columns: ['party_id'])]
#[ORM\UniqueConstraint(name: 'uniq_party_bank_account_primary', columns: ['party_id', 'primary_flag'])]
class PartyBankAccount implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 34)]
    private string $iban;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $bic;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    #[ORM\Column(name: 'primary_flag', type: 'boolean', nullable: true)]
    private ?bool $primaryFlag;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        Iban $iban,
        ?string $bic = null,
        string $label = '',
        bool $primary = false,
    ) {
        $this->id = Id::generate();
        $this->primaryFlag = $primary ? true : null;
        $this->iban = $iban->toString();
        $this->bic = $this->validateBic($bic);
        $this->label = trim($label);
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
        return true === $this->primaryFlag;
    }

    public function relabel(string $label): void
    {
        $this->label = trim($label);
    }

    /**
     * @throws \DomainException when the BIC has neither 8 nor 11 characters
     */
    public function changeBic(?string $bic): void
    {
        $this->bic = $this->validateBic($bic);
    }

    public function makePrimary(): void
    {
        $this->primaryFlag = true;
    }

    public function demote(): void
    {
        $this->primaryFlag = null;
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
