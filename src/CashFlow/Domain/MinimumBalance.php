<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * The balance a firm wants to keep, in CZK. The forecast answers the first day
 * its projected balance falls below it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_minimum_balance')]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_minimum_balance_firm', columns: ['legal_entity_id'])]
class MinimumBalance implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        Money $amount,
    ) {
        $this->id = Id::generate();
        $this->change($amount);
    }

    public function change(Money $amount): void
    {
        if (Currency::CZK !== $amount->currency()) {
            throw new \InvalidArgumentException('A minimum balance is in CZK, the currency of the forecast.');
        }
        $this->amountMinor = $amount->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, Currency::CZK);
    }
}
