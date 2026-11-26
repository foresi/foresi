<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A meeting series archived over the API and MCP (ADR 0032). Doing it twice changes nothing.
 */
#[Operation(
    name: 'meetings.series.archive',
    method: 'POST',
    path: '/api/v1/meetings/series/{seriesId}/archive',
    tool: 'foresi_archive_meeting_series',
    scope: 'meetings:write',
    title: 'Archive a meeting series',
    description: 'Archives a series: it takes no new meetings, and its meetings stay readable and editable. Only the owners and the members of the series archive it.',
    response: 'MeetingSeries',
    staffOnly: true,
)]
final readonly class ArchiveSeriesInput
{
    public function __construct(
        #[Field('The id of the series.', format: 'uuid')]
        public string $seriesId,
    ) {
    }
}
