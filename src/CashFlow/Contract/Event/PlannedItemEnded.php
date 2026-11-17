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
 * A planned item has no occurrence after a date. Its name and counterparty are no facts.
 */
#[DomainEvent('cash-flow.planned_item.ended', version: 1)]
final readonly class PlannedItemEnded implements DomainEventPayload
{
    /**
     * @param string $endsOn the last day an occurrence may fall on, ISO 8601
     */
    public function __construct(
        public string $plannedItemId,
        public string $firmId,
        public PlannedDirection $direction,
        public string $endsOn,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('planned_item', $this->plannedItemId);
    }
}
