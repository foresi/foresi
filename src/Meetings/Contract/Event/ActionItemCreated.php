<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A task was made from a meeting's minutes as an action item. The task records
 * Work's own work.task.created beside it. The payload is thin (ADR 0030): read
 * the meeting and the task themselves for the rest.
 */
#[DomainEvent('meetings.action_item.created', version: 1)]
final readonly class ActionItemCreated implements DomainEventPayload
{
    public function __construct(
        public string $meetingId,
        public ?string $seriesId,
        public string $taskId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('meeting', $this->meetingId);
    }
}
