<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A planned item was deleted, with all its occurrences. Its name and counterparty are no facts.
 */
#[DomainEvent('cash-flow.planned_item.deleted', version: 1)]
final readonly class PlannedItemDeleted implements DomainEventPayload
{
    public function __construct(
        public string $plannedItemId,
        public string $firmId,
        public PlannedDirection $direction,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('planned_item', $this->plannedItemId);
    }
}
