<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Audit\Domain\Audited;
use App\Instance\Domain\TenantId;
use App\Instance\Domain\TenantOwned;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment of a recorded received document, built the way the payments of
 * issued invoices are (ADR 0019): the day, the amount in the document's
 * currency, the method and the bank movement it came from, if any. A document
 * may have several; what is paid and what remains are their sum against the
 * total, never stored. The amount is positive for a credit note too, where it
 * is money the supplier returned. A bank movement is named by id only, so
 * the pairing of outgoing movements can be built on the record later.
 */
#[ORM\Entity]
#[ORM\Table(name: 'received_document_payment')]
#[ORM\Index(name: 'idx_received_document_payment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_received_document_payment_document', columns: ['received_document_id'])]
#[ORM\Index(name: 'idx_received_document_payment_movement', columns: ['bank_movement_id'])]
class ReceivedDocumentPayment implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'paid_on', type: 'date_immutable')]
    private \DateTimeImmutable $paidOn;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
    private Currency $currency;

    /**
     * @throws ReceivedDocumentRefused with PAYMENT_AMOUNT for an amount not above zero or in another currency than the document's,
     *                                 BANK_MOVEMENT_INVALID for a movement id that is none
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ReceivedDocument::class)]
        #[ORM\JoinColumn(name: 'received_document_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly ReceivedDocument $document,
        \DateTimeImmutable $paidOn,
        Money $amount,
        #[ORM\Column(type: 'string', length: 16, enumType: PaymentMethod::class)]
        private readonly PaymentMethod $method,
        #[ORM\Column(name: 'bank_movement_id', type: 'guid', nullable: true)]
        private readonly ?string $bankMovementId,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ($amount->isZero() || $amount->isNegative() || $amount->currency() !== $document->currency()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::PAYMENT_AMOUNT);
        }
        if (null !== $bankMovementId && !Id::isValid($bankMovementId)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::BANK_MOVEMENT_INVALID);
        }
        $this->id = Id::generate();
        $this->paidOn = new \DateTimeImmutable($paidOn->format('Y-m-d'));
        $this->amountMinor = $amount->minor();
        $this->currency = $amount->currency();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function document(): ReceivedDocument
    {
        return $this->document;
    }

    public function paidOn(): \DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function bankMovementId(): ?string
    {
        return $this->bankMovementId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
