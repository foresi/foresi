<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod as StatusPeriodView;

/**
 * A status of the owner from a day on, until the next period of its kind: the
 * owner became a VAT payer on 12 November, say. Two periods of one kind never
 * start on the same day. Only voluntary sickness insurance that is on carries
 * a base, its monthly base in CZK.
 */
#[ORM\Entity]
#[ORM\Table(name: 'taxes_status_period')]
#[ORM\UniqueConstraint(name: 'uniq_taxes_status_period_kind_day', columns: ['kind', 'valid_from'])]
class StatusPeriod implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'base_minor', type: 'bigint', nullable: true)]
    private ?int $baseMinor;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: OwnerProfile::class)]
        #[ORM\JoinColumn(name: 'profile_id', nullable: false, onDelete: 'CASCADE')]
        private readonly OwnerProfile $profile,
        #[ORM\Column(length: 40, enumType: StatusKind::class)]
        private readonly StatusKind $kind,
        #[ORM\Column(length: 20)]
        private readonly string $value,
        #[ORM\Column(name: 'valid_from', type: 'date_immutable')]
        private readonly \DateTimeImmutable $validFrom,
        ?Money $base = null,
    ) {
        if (!\in_array($value, $kind->values(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a value of the status %s.', $value, $kind->value));
        }
        if ($kind->needsBase($value) !== $base instanceof Money) {
            throw new \InvalidArgumentException('Only voluntary sickness insurance that is on has a base, and it always has one.');
        }
        if ($base instanceof Money && (Currency::CZK !== $base->currency() || $base->isNegative() || $base->isZero())) {
            throw new \InvalidArgumentException('A base is a positive amount in CZK.');
        }
        $this->id = Id::generate();
        $this->baseMinor = $base?->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function profile(): OwnerProfile
    {
        return $this->profile;
    }

    public function kind(): StatusKind
    {
        return $this->kind;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function validFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function base(): ?Money
    {
        return null === $this->baseMinor ? null : Money::ofMinor($this->baseMinor, Currency::CZK);
    }

    public function view(): StatusPeriodView
    {
        return new StatusPeriodView($this->id, $this->kind, $this->value, $this->validFrom, $this->base());
    }
}
