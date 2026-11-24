<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * How the costs paired with a Cash flow planned item are split, from one
 * month on. The mapping of an occurrence is the version with the latest
 * month on or before the occurrence's month; a version without an
 * allocation ends the mapping from its month. One version per month: setting
 * a month again replaces it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_planned_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_margin_planned_allocation', columns: ['planned_item_id', 'from_month'])]
class PlannedAllocation implements Audited
{
    /** @var array<mixed>|null */
    #[ORM\Column(name: 'allocation', type: 'json', nullable: true)]
    private ?array $allocation;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'planned_item_id', type: 'guid')]
        private readonly string $plannedItemId,
        #[ORM\Column(name: 'from_month', type: 'date_immutable')]
        private readonly \DateTimeImmutable $fromMonth,
        ?Allocation $allocation,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'created_by', length: 64)]
        private string $createdBy,
    ) {
        $this->allocation = $allocation?->toArray();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function plannedItemId(): string
    {
        return $this->plannedItemId;
    }

    /**
     * The first day of the month the version applies from.
     */
    public function fromMonth(): \DateTimeImmutable
    {
        return $this->fromMonth;
    }

    /**
     * Null when the mapping ends from this month.
     */
    public function allocation(): ?Allocation
    {
        return null === $this->allocation ? null : Allocation::fromArray($this->allocation);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function replace(?Allocation $allocation, \DateTimeImmutable $at, string $by): void
    {
        $this->allocation = $allocation?->toArray();
        $this->createdAt = $at;
        $this->createdBy = $by;
    }
}
