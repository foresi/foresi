<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * A person's decision about one bank movement to or from the tax office: it
 * pays the VAT period named, or, with no period, it is no VAT payment. It
 * wins over the automatic matching; removing it gives the movement back to
 * the automatic matching.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vat_payment_assignment')]
class PaymentAssignment implements Audited
{
    public const int MAX_MOVEMENT_ID = 64;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'movement_id', length: self::MAX_MOVEMENT_ID)]
        private string $movementId,
        #[ORM\Column(name: 'period_key', length: PeriodEntry::MAX_KEY, nullable: true)]
        private ?string $periodKey,
        #[ORM\Column(name: 'assigned_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $assignedAt,
        #[ORM\Column(name: 'assigned_by', length: 64)]
        private string $assignedBy,
    ) {
    }

    public function movementId(): string
    {
        return $this->movementId;
    }

    public function periodKey(): ?string
    {
        return $this->periodKey;
    }

    public function assignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function assignedBy(): string
    {
        return $this->assignedBy;
    }

    public function reassign(?string $periodKey, \DateTimeImmutable $at, string $by): void
    {
        $this->periodKey = $periodKey;
        $this->assignedAt = $at;
        $this->assignedBy = $by;
    }
}
