<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\Audit\Domain\Audited;
use App\Instance\Domain\TenantId;
use App\Instance\Domain\TenantOwned;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * The balance a firm wants to keep, in CZK. The forecast answers the first day
 * its projected balance falls below it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_minimum_balance')]
#[ORM\Index(name: 'idx_cash_flow_minimum_balance_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_minimum_balance_firm', columns: ['legal_entity_id'])]
class MinimumBalance implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

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

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
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
