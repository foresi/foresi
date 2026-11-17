<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\Audit\Domain\Audited;
use App\Instance\Domain\TenantId;
use App\Instance\Domain\TenantOwned;
use App\Party\Domain\FirmBankAccount;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * The balance of a firm's bank account at the end of a day, entered by hand or
 * through the API. One per account and day: entering the day again corrects it.
 * The latest one is where the forecast starts while no bank feed adds the
 * movements booked after it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_checkpoint')]
#[ORM\Index(name: 'idx_cash_flow_checkpoint_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_checkpoint_day', columns: ['firm_bank_account_id', 'balance_on'])]
class Checkpoint implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

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

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
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
