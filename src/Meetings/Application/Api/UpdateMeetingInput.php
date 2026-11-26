<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A meeting changed over the API and MCP (ADR 0032): the fields given change,
 * the rest stay. The minutes change with foresi_set_meeting_minutes.
 */
#[Operation(
    name: 'meetings.meetings.update',
    method: 'PATCH',
    path: '/api/v1/meetings/{meetingId}',
    tool: 'foresi_update_meeting',
    scope: 'meetings:write',
    title: 'Update a meeting',
    description: 'Changes the title, the day, the time, the attendees or the linked records of a meeting and keeps the rest; starts_at null clears the time. attendee_ids and links replace what the meeting had. The minutes change with foresi_set_meeting_minutes. Whoever sees the meeting changes it.',
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class UpdateMeetingInput
{
    /**
     * @param list<string>|Omitted                $attendeeIds
     * @param list<array<array-key, mixed>>|Omitted $links
     */
    public function __construct(
        #[Field('The id of the meeting.', format: 'uuid')]
        public string $meetingId,
        #[Field('What it is called.', maxLength: Meeting::MAX_TITLE)]
        public string|Omitted $title = new Omitted(),
        #[Field('The day it is held on, YYYY-MM-DD.', format: 'date')]
        public string|Omitted $heldOn = new Omitted(),
        #[Field('The time it starts, HH:MM in the instance\'s zone; null for none.')]
        public string|Omitted|null $startsAt = new Omitted(),
        #[Field('The people of the firm who attend; they replace the ones before.', items: 'string')]
        public array|Omitted $attendeeIds = new Omitted(),
        #[Field('The records it is about, each {"kind", "id"}; they replace the ones before.', items: Field::OBJECTS)]
        public array|Omitted $links = new Omitted(),
    ) {
    }
}
