<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'calendar.feeds.revoke',
    method: 'POST',
    path: '/api/v1/calendar/feeds/{feedId}/revoke',
    tool: 'foresi_revoke_calendar_feed',
    scope: 'calendar:write',
    title: 'Revoke a calendar feed',
    description: 'Revokes one of your calendar feeds: its address answers 404 from now on and cannot be brought back; the calendar app keeps what it last fetched until it is removed there. The feed stays listed as revoked. To keep a calendar, create a new feed first with foresi_create_calendar_feed.',
    destructive: true,
    response: 'CalendarFeed',
    staffOnly: true,
)]
final readonly class RevokeFeedInput
{
    public function __construct(
        #[Field('The id of the feed.', format: 'uuid')]
        public string $feedId,
    ) {
    }
}
