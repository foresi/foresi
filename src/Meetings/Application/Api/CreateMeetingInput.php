<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new meeting over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'meetings.meetings.create',
    method: 'POST',
    path: '/api/v1/meetings',
    tool: 'foresi_create_meeting',
    scope: 'meetings:write',
    title: 'Create a meeting',
    description: 'Creates a meeting of a series (series_id, from foresi_list_meeting_series) or a one-off meeting without one. A meeting of a series starts with its agenda template as the minutes, unless minutes are given, and with its default attendees unless attendee_ids are given; its title defaults to the series name and the day, such as "Production weekly – 2026-12-01". A one-off meeting is seen by its attendees and the person creating it; a meeting of a series by whoever sees the series, and its attendees. starts_at is the time of the day in the instance\'s zone. links are the records the meeting is about, each {"kind": "project" or "contact", "id": …}.',
    status: 201,
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class CreateMeetingInput
{
    /**
     * @param list<string>|null             $attendeeIds
     * @param list<array<array-key, mixed>> $links
     */
    public function __construct(
        #[Field('The day it is held on, YYYY-MM-DD.', format: 'date')]
        public string $heldOn,
        #[Field('The id of its series; a one-off meeting when left out.', format: 'uuid')]
        public ?string $seriesId = null,
        #[Field('What it is called; the series name and the day when left out. Required for a one-off meeting.', maxLength: Meeting::MAX_TITLE)]
        public ?string $title = null,
        #[Field('The time it starts, HH:MM in the instance\'s zone; none when left out.')]
        public ?string $startsAt = null,
        #[Field('The people of the firm who attend, by the ids under people of foresi_list_meeting_series; the default attendees of the series when left out.', items: 'string')]
        public ?array $attendeeIds = null,
        #[Field('The records it is about, each {"kind": "project" or "contact", "id": the record\'s id}.', items: Field::OBJECTS)]
        public array $links = [],
        #[Field('The minutes in Markdown; the agenda template of the series when left out.', maxLength: Meeting::MAX_MINUTES)]
        public ?string $minutes = null,
    ) {
    }
}
