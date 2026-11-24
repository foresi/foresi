<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * Who bears the costs of one supplier by default, from one month on: the
 * firm itself or one client, never a split. Versioned like a planned
 * mapping: the version with the latest month on or before the cost's month
 * applies, and a version without a recipient ends the default.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_supplier_default')]
#[ORM\UniqueConstraint(name: 'uniq_margin_supplier_default', columns: ['supplier_id', 'from_month'])]
class SupplierDefault implements Audited
{
    #[ORM\Column(name: 'recipient', length: 36, nullable: true)]
    private ?string $recipient;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'supplier_id', type: 'guid')]
        private readonly string $supplierId,
        #[ORM\Column(name: 'from_month', type: 'date_immutable')]
        private readonly \DateTimeImmutable $fromMonth,
        ?Recipient $recipient,
    ) {
        $this->recipient = $recipient?->toString();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function supplierId(): string
    {
        return $this->supplierId;
    }

    /**
     * The first day of the month the version applies from.
     */
    public function fromMonth(): \DateTimeImmutable
    {
        return $this->fromMonth;
    }

    /**
     * Null when the default ends from this month.
     */
    public function recipient(): ?Recipient
    {
        return null === $this->recipient ? null : Recipient::fromString($this->recipient);
    }

    public function replace(?Recipient $recipient): void
    {
        $this->recipient = $recipient?->toString();
    }
}
