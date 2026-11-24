<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * A line of a recurring invoice template marked as rebilling a Cash flow
 * planned item: each invoice issued from the template pairs the line with the
 * item's occurrence of the invoice's tax point. A line rebills one item. The
 * link starts counting on the day it was made, so what was paid before is not
 * called unrebilled.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_rebill_link')]
#[ORM\UniqueConstraint(name: 'uniq_margin_rebill_link_line', columns: ['recurring_line_id'])]
class RebillLink implements Audited
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'recurring_line_id', type: 'guid')]
        private readonly string $recurringLineId,
        #[ORM\Column(name: 'planned_item_id', type: 'guid')]
        private string $plannedItemId,
        #[ORM\Column(name: 'created_on', type: 'date_immutable')]
        private \DateTimeImmutable $createdOn,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function recurringLineId(): string
    {
        return $this->recurringLineId;
    }

    public function plannedItemId(): string
    {
        return $this->plannedItemId;
    }

    /**
     * The day the link was made, or last pointed at another item.
     */
    public function createdOn(): \DateTimeImmutable
    {
        return $this->createdOn;
    }

    public function pointAt(string $plannedItemId, \DateTimeImmutable $today): void
    {
        $this->plannedItemId = $plannedItemId;
        $this->createdOn = $today;
    }
}
