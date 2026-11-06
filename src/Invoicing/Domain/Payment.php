<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money a firm received, once, or sent back as a refund. Which invoices it pays, or
 * refunds, is told by its allocations, whose amounts add up to the amount of the
 * payment. The amount is positive either way: the direction carries the sign. A payment is never deleted:
 * cancelling it removes its allocations and keeps the row, with who and when, so a
 * bank movement it came from can be paired again and the log stays whole.
 *
 * The movement is named by its id only: invoicing knows nothing of banking. It
 * pays through one payment that is not cancelled: live_bank_movement_id is the
 * movement while the payment is live and NULL once it is cancelled, and a plain
 * unique index over it keeps the rule, as primary_flag does for FirmBankAccount.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_payment_firm_received', columns: ['tenant_id', 'legal_entity_id', 'received_on'])]
#[ORM\Index(name: 'idx_payment_bank_movement', columns: ['bank_movement_id'])]
#[ORM\Index(name: 'idx_payment_firm', columns: ['legal_entity_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_active_movement', columns: ['live_bank_movement_id'])]
class Payment implements TenantOwned, Audited
{
    public const int MAX_NOTE = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
    private Currency $currency;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancelled_by', type: 'guid', nullable: true)]
    private ?string $cancelledBy = null;

    /** The movement while the payment is live, NULL once it is cancelled: what the unique index holds. */
    #[ORM\Column(name: 'live_bank_movement_id', type: 'guid', nullable: true)]
    private ?string $liveBankMovementId;

    /**
     * @throws \InvalidArgumentException for an amount that is not positive, a note past MAX_NOTE, or a movement
     *                                   named for a payment that did not come from one, or missing for one that did
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(name: 'received_on', type: 'date_immutable')]
        private readonly \DateTimeImmutable $receivedOn,
        Money $amount,
        #[ORM\Column(type: 'string', length: 16, enumType: PaymentMethod::class)]
        private readonly PaymentMethod $method,
        #[ORM\Column(type: 'string', length: 16, enumType: PaymentSource::class)]
        private readonly PaymentSource $source,
        #[ORM\Column(name: 'bank_movement_id', type: 'guid', nullable: true)]
        private readonly ?string $bankMovementId,
        #[ORM\Column(type: 'string', length: self::MAX_NOTE)]
        private readonly string $note,
        #[ORM\Column(name: 'created_by', type: 'guid', nullable: true)]
        private readonly ?string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'string', length: 8, enumType: PaymentDirection::class, options: ['default' => 'incoming'])]
        private readonly PaymentDirection $direction = PaymentDirection::Incoming,
    ) {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new \InvalidArgumentException('A payment is a positive amount.');
        }
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new \InvalidArgumentException('The note of a payment is too long.');
        }
        if ((PaymentSource::BankMovement === $source) !== (null !== $bankMovementId)) {
            throw new \InvalidArgumentException('A payment from a bank movement names it, and only such a payment does.');
        }
        $this->id = Id::generate();
        $this->liveBankMovementId = $bankMovementId;
        $this->amountMinor = $amount->minor();
        $this->currency = $amount->currency();
    }

    /**
     * Once only: a cancelled payment stays cancelled, with who did it first.
     */
    public function cancel(\DateTimeImmutable $at, ?string $userId): void
    {
        if ($this->isCancelled()) {
            return;
        }
        $this->cancelledAt = $at;
        $this->cancelledBy = $userId;
        $this->liveBankMovementId = null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function receivedOn(): \DateTimeImmutable
    {
        return $this->receivedOn;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function direction(): PaymentDirection
    {
        return $this->direction;
    }

    public function isRefund(): bool
    {
        return PaymentDirection::Refund === $this->direction;
    }

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function source(): PaymentSource
    {
        return $this->source;
    }

    public function bankMovementId(): ?string
    {
        return $this->bankMovementId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledBy(): ?string
    {
        return $this->cancelledBy;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
