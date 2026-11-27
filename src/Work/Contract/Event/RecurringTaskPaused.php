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
 * A recurring task was paused: it creates nothing until it is resumed. The payload is thin (ADR 0030): read the recurring task for its title and
 * schedule; the feed shows it to the people of the organisation who see its project.
 */
#[DomainEvent('work.recurring_task.paused', version: 1)]
final readonly class RecurringTaskPaused implements DomainEventPayload
{
    public function __construct(
        public string $recurringTaskId,
        public string $projectId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('recurring_task', $this->recurringTaskId);
    }
}
