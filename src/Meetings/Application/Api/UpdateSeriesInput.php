<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A meeting series changed over the API and MCP (ADR 0032): the fields given
 * change, the rest stay.
 */
#[Operation(
    name: 'meetings.series.update',
    method: 'PATCH',
    path: '/api/v1/meetings/series/{seriesId}',
    tool: 'foresi_update_meeting_series',
    scope: 'meetings:write',
    title: 'Update a meeting series',
    description: 'Changes the fields given of a series and keeps the rest; default_project_id null clears it. member_ids replaces the members, and default_attendee_ids the members who start as attendees of a new meeting (each is made a member). A changed agenda template does not touch the minutes of existing meetings. Only the owners and the members of the series change it.',
    response: 'MeetingSeries',
    staffOnly: true,
)]
final readonly class UpdateSeriesInput
{
    /**
     * @param list<string>|Omitted $memberIds
     * @param list<string>|Omitted $defaultAttendeeIds
     */
    public function __construct(
        #[Field('The id of the series.', format: 'uuid')]
        public string $seriesId,
        #[Field('What the meetings are.', maxLength: MeetingSeries::MAX_NAME)]
        public string|Omitted $name = new Omitted(),
        #[Field('members or organisation.', enum: ['members', 'organisation'])]
        public string|Omitted $visibility = new Omitted(),
        #[Field('The agenda in Markdown, for new meetings.', maxLength: MeetingSeries::MAX_TEMPLATE)]
        public string|Omitted $agendaTemplate = new Omitted(),
        #[Field('The project a task from the minutes is offered first; null for none.', format: 'uuid')]
        public string|Omitted|null $defaultProjectId = new Omitted(),
        #[Field('The people of the firm who belong to the series; they replace the ones before.', items: 'string')]
        public array|Omitted $memberIds = new Omitted(),
        #[Field('The members who start as attendees of each new meeting; they replace the ones before.', items: 'string')]
        public array|Omitted $defaultAttendeeIds = new Omitted(),
    ) {
    }
}
