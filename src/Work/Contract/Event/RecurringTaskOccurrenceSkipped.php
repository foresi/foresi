<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * The task of one date of a recurring task will not be created. The payload is thin (ADR 0030): read the recurring task for its title and
 * schedule; the feed shows it to the people of the organisation who see its project.
 */
#[DomainEvent('work.recurring_task.occurrence_skipped', version: 1)]
final readonly class RecurringTaskOccurrenceSkipped implements DomainEventPayload
{
    public function __construct(
        public string $recurringTaskId,
        public string $projectId,
        /** The skipped date, YYYY-MM-DD. */
        public string $on,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('recurring_task', $this->recurringTaskId);
    }
}
