<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * The balance of a firm's bank account at the end of a day, entered by hand or
 * through the API. One per account and day: entering the day again corrects it.
 * The latest one is where the forecast starts while no bank feed adds the
 * movements booked after it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_checkpoint')]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_checkpoint_day', columns: ['firm_bank_account_id', 'balance_on'])]
class Checkpoint implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

    /**
     * @param Money $amount in the currency of the account
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: FirmBankAccount::class)]
        #[ORM\JoinColumn(name: 'firm_bank_account_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly FirmBankAccount $account,
        #[ORM\Column(name: 'balance_on', type: 'date_immutable')]
        private readonly \DateTimeImmutable $on,
        Money $amount,
        \DateTimeImmutable $recordedAt,
    ) {
        $this->id = Id::generate();
        $this->correct($amount, $recordedAt);
    }

    public function correct(Money $amount, \DateTimeImmutable $recordedAt): void
    {
        if ($amount->currency() !== $this->account->currency()) {
            throw new \InvalidArgumentException('A checkpoint is in the currency of its account.');
        }
        $this->amountMinor = $amount->minor();
        $this->recordedAt = $recordedAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function account(): FirmBankAccount
    {
        return $this->account;
    }

    public function on(): \DateTimeImmutable
    {
        return $this->on;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->account->currency());
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
