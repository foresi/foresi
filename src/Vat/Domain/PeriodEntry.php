<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * What a person entered for one VAT period: the amount of the return they
 * filed, which replaces the estimate, and the mark that the period is
 * settled, with who set it, when and why. A period without an entry is what
 * the documents and the bank say. The row stays while it holds anything.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vat_period_entry')]
class PeriodEntry implements Audited
{
    public const int MAX_KEY = 7;
    public const int MAX_NOTE = 500;

    #[ORM\Column(name: 'return_amount_minor', type: 'bigint', nullable: true)]
    private ?int $returnAmountMinor = null;

    #[ORM\Column(name: 'settled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    #[ORM\Column(name: 'settled_by', length: 64, nullable: true)]
    private ?string $settledBy = null;

    #[ORM\Column(name: 'settled_note', length: self::MAX_NOTE, nullable: true)]
    private ?string $settledNote = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'period_key', length: self::MAX_KEY)]
        private string $periodKey,
    ) {
    }

    public function periodKey(): string
    {
        return $this->periodKey;
    }

    public function returnAmount(): ?Money
    {
        return null === $this->returnAmountMinor ? null : Money::ofMinor($this->returnAmountMinor, Currency::CZK);
    }

    public function enterReturnAmount(?Money $amount): void
    {
        $this->returnAmountMinor = $amount?->minor();
    }

    public function settle(\DateTimeImmutable $at, string $by, ?string $note): void
    {
        $this->settledAt = $at;
        $this->settledBy = $by;
        $this->settledNote = $note;
    }

    public function reopen(): void
    {
        $this->settledAt = null;
        $this->settledBy = null;
        $this->settledNote = null;
    }

    public function settledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    public function settledBy(): ?string
    {
        return $this->settledBy;
    }

    public function settledNote(): ?string
    {
        return $this->settledNote;
    }

    public function isEmpty(): bool
    {
        return null === $this->returnAmountMinor && !$this->settledAt instanceof \DateTimeImmutable;
    }
}
