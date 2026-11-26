<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new meeting series over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'meetings.series.create',
    method: 'POST',
    path: '/api/v1/meetings/series',
    tool: 'foresi_create_meeting_series',
    scope: 'meetings:write',
    title: 'Create a meeting series',
    description: 'Creates a series of recurring meetings, such as "Production weekly", with its people. A series for its members (the default) and its meetings are seen by its members, the attendees of each meeting and the owners only; a series for the organisation by everyone in the firm. The agenda_template is Markdown copied into the minutes of each new meeting of the series. The members are people of the firm by the ids foresi_list_meeting_series gives under people; the person creating the series becomes a member. The default attendees are members who start as attendees of each new meeting. The name is unique in the firm.',
    status: 201,
    response: 'MeetingSeries',
    staffOnly: true,
)]
final readonly class CreateSeriesInput
{
    /**
     * @param list<string> $memberIds
     * @param list<string> $defaultAttendeeIds
     */
    public function __construct(
        #[Field('What the meetings are, such as "Production weekly".', maxLength: MeetingSeries::MAX_NAME)]
        public string $name,
        #[Field('members for its members and attendees only, organisation for everyone in the firm.', enum: ['members', 'organisation'])]
        public string $visibility = 'members',
        #[Field('The agenda in Markdown, copied into the minutes of each new meeting.', maxLength: MeetingSeries::MAX_TEMPLATE)]
        public string $agendaTemplate = '',
        #[Field('The project a task from the minutes is offered first, as foresi_search_projects gives it; none when left out.', format: 'uuid')]
        public ?string $defaultProjectId = null,
        #[Field('The people of the firm who belong to the series, by the ids under people of foresi_list_meeting_series.', items: 'string')]
        public array $memberIds = [],
        #[Field('The members who start as attendees of each new meeting; each is made a member.', items: 'string')]
        public array $defaultAttendeeIds = [],
    ) {
    }
}
