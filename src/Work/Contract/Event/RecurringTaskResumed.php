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
 * A paused recurring task goes on from the first date on or after the day it was resumed; the dates of the pause are not created. The payload is thin (ADR 0030): read the recurring task for its title and
 * schedule; the feed shows it to the people of the organisation who see its project.
 */
#[DomainEvent('work.recurring_task.resumed', version: 1)]
final readonly class RecurringTaskResumed implements DomainEventPayload
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
