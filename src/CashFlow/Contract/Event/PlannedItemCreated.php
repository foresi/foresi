<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

/**
 * A planned item was created, one-off or recurring. Its name and counterparty are no facts.
 */
#[DomainEvent('cash-flow.planned_item.created', version: 1)]
final readonly class PlannedItemCreated implements DomainEventPayload
{
    /**
     * @param string $amount the amount of one occurrence, a decimal string such as "1500.00"
     */
    public function __construct(
        public string $plannedItemId,
        public string $firmId,
        public PlannedDirection $direction,
        public string $amount,
        public string $currency,
        public bool $recurring,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('planned_item', $this->plannedItemId);
    }
}
