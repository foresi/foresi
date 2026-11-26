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
 * A meeting came back from the trash. The payload is thin (ADR 0030): read the meeting itself for the rest.
 */
#[DomainEvent('meetings.meeting.restored', version: 1)]
final readonly class MeetingRestored implements DomainEventPayload
{
    public function __construct(
        public string $meetingId,
        public ?string $seriesId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('meeting', $this->meetingId);
    }
}
