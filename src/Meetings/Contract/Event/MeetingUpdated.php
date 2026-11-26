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
 * A meeting's title, day, time, attendees, links or custom fields (the keys in customFields) changed. The payload is thin (ADR 0030): read the meeting itself for the rest.
 */
#[DomainEvent('meetings.meeting.updated', version: 1)]
final readonly class MeetingUpdated implements DomainEventPayload
{
    public function __construct(
        public string $meetingId,
        public ?string $seriesId,
        /** @var list<string> the keys of the custom fields whose value changed */
        public array $customFields = [],
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('meeting', $this->meetingId);
    }
}
