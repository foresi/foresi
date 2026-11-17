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
 * One occurrence of a planned item will not happen; the others stay. Its name and counterparty are no facts.
 */
#[DomainEvent('cash-flow.planned_item.occurrence_skipped', version: 1)]
final readonly class PlannedItemOccurrenceSkipped implements DomainEventPayload
{
    /**
     * @param string $occurrenceOn the date of the skipped occurrence, ISO 8601
     */
    public function __construct(
        public string $plannedItemId,
        public string $firmId,
        public PlannedDirection $direction,
        public string $occurrenceOn,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('planned_item', $this->plannedItemId);
    }
}
