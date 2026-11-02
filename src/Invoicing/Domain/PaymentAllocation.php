<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The part of a payment that pays one invoice. The amount is a part of the payment;
 * the settlement is a small difference written off, which counts toward the invoice
 * but was never received, so it stays outside the sum of the payment. It may be
 * negative, when a little more came than was due. An allocation is part of the
 * history of its invoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_allocation')]
#[ORM\Index(name: 'idx_payment_allocation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_payment_allocation_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_payment_allocation_payment', columns: ['payment_id'])]
class PaymentAllocation implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(name: 'settlement_minor', type: 'bigint', options: ['default' => 0])]
    private int $settlementMinor;

    /**
     * @throws \InvalidArgumentException for an amount that is not positive, or an amount in another currency than the payment and the invoice
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Payment::class)]
        #[ORM\JoinColumn(name: 'payment_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Payment $payment,
        #[ORM\ManyToOne(targetEntity: Invoice::class)]
        #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Invoice $invoice,
        Money $amount,
        ?Money $settlement = null,
    ) {
        $currency = $payment->amount()->currency();
        if ($amount->currency() !== $currency || $invoice->currency() !== $currency || ($settlement instanceof Money && $settlement->currency() !== $currency)) {
            throw new \InvalidArgumentException('An allocation is in the currency of its payment and its invoice.');
        }
        if ($amount->isZero() || $amount->isNegative()) {
            throw new \InvalidArgumentException('An allocation takes a positive part of the payment.');
        }
        $this->id = Id::generate();
        $this->amountMinor = $amount->minor();
        $this->settlementMinor = $settlement?->minor() ?? 0;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->invoice;
    }

    public function payment(): Payment
    {
        return $this->payment;
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->invoice->currency());
    }

    public function settlement(): Money
    {
        return Money::ofMinor($this->settlementMinor, $this->invoice->currency());
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
