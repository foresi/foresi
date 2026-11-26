<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A meeting put in the trash over the API and MCP (ADR 0032). Doing it twice changes nothing.
 */
#[Operation(
    name: 'meetings.meetings.trash',
    method: 'POST',
    path: '/api/v1/meetings/{meetingId}/trash',
    tool: 'foresi_trash_meeting',
    scope: 'meetings:write',
    title: 'Put a meeting in the trash',
    description: 'Puts a meeting in the trash: it leaves the lists and is removed for good after 30 days unless restored with foresi_restore_meeting. Only the person who created it and the owners put it in the trash.',
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class TrashMeetingInput
{
    public function __construct(
        #[Field('The id of the meeting.', format: 'uuid')]
        public string $meetingId,
    ) {
    }
}
