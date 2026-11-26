<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A meeting taken back from the trash over the API and MCP (ADR 0032). Doing it twice changes nothing.
 */
#[Operation(
    name: 'meetings.meetings.restore',
    method: 'POST',
    path: '/api/v1/meetings/{meetingId}/restore',
    tool: 'foresi_restore_meeting',
    scope: 'meetings:write',
    title: 'Restore a meeting',
    description: 'Takes a meeting back from the trash, with its minutes, attendees and links, within 30 days of trashing it. Only the person who created it and the owners restore it.',
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class RestoreMeetingInput
{
    public function __construct(
        #[Field('The id of the meeting.', format: 'uuid')]
        public string $meetingId,
    ) {
    }
}
