<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Foresi\Shared\Domain\Id;

/**
 * One VAT status of the instance's firm from a day on, until the next one
 * begins (ADR 0039: one firm, so no firm id). A registered payer has a period
 * length and nobody else has one. Only the stretch made from the status kept
 * before the stretches existed has no day; a new one always has.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_vat_status_period')]
#[ORM\UniqueConstraint(name: 'uniq_party_vat_status_period_from', columns: ['valid_from'])]
class VatStatusPeriod implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /**
     * @throws \InvalidArgumentException for a period length on any status but registered, or none on it
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 32, enumType: VatStatus::class)]
        private VatStatus $status,
        #[ORM\Column(name: 'period_length', type: 'string', length: 16, nullable: true, enumType: VatPeriodLength::class)]
        private ?VatPeriodLength $periodLength,
        #[ORM\Column(name: 'valid_from', type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $validFrom,
    ) {
        if ((VatStatus::Registered === $status) !== $periodLength instanceof VatPeriodLength) {
            throw new \InvalidArgumentException('A registered payer has a VAT period length, and no other status has one.');
        }
        $this->id = Id::generate();
        $this->validFrom = $validFrom?->setTime(0, 0);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): VatStatus
    {
        return $this->status;
    }

    public function periodLength(): ?VatPeriodLength
    {
        return $this->periodLength;
    }

    public function validFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function stretch(): VatStretch
    {
        return new VatStretch($this->id, $this->status->registration(), $this->periodLength, $this->validFrom);
    }
}
