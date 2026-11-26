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
 * The minutes of a meeting saved over the API and MCP (ADR 0032), refused when
 * someone saved them since they were read.
 */
#[Operation(
    name: 'meetings.meetings.set_minutes',
    method: 'PUT',
    path: '/api/v1/meetings/{meetingId}/minutes',
    tool: 'foresi_set_meeting_minutes',
    scope: 'meetings:write',
    title: 'Set the minutes of a meeting',
    description: 'Replaces the minutes of a meeting with the Markdown given. Send the version foresi_get_meeting returned: when someone saved the minutes since, the call is refused with stale_minutes and the current_version, nothing is merged, and you read the meeting again before you write. Each save that changes the text raises the version by one.',
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class SetMinutesInput
{
    public function __construct(
        #[Field('The id of the meeting.', format: 'uuid')]
        public string $meetingId,
        #[Field('The minutes in Markdown; empty for none.', maxLength: Meeting::MAX_MINUTES)]
        public string $minutes,
        #[Field('The version of the minutes they were read at, as foresi_get_meeting gives it.')]
        public int $version,
    ) {
    }
}
