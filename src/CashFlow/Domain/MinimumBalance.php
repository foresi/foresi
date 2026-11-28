<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * The balance the firm of the instance wants to keep, in CZK. The forecast answers the first day
 * its projected balance falls below it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_minimum_balance')]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_minimum_balance_one_row', columns: ['one_row'])]
class MinimumBalance implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    /**
     * Always true: with its unique index (and a check on PostgreSQL) the table holds
     * at most one row, the settings of the instance's one firm (ADR 0039).
     */
    #[ORM\Column(name: 'one_row', type: 'boolean', options: ['default' => true])]
    private bool $oneRow = true;

    public function __construct(
        Money $amount,
    ) {
        $this->id = Id::generate();
        $this->change($amount);
    }

    public function change(Money $amount): void
    {
        if (Currency::of('CZK') !== $amount->currency()) {
            throw new \InvalidArgumentException('A minimum balance is in CZK, the currency of the forecast.');
        }
        $this->amountMinor = $amount->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, Currency::of('CZK'));
    }
}
