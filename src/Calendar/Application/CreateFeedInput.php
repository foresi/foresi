<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'calendar.feeds.create',
    method: 'POST',
    path: '/api/v1/calendar/feeds',
    tool: 'foresi_create_calendar_feed',
    scope: 'calendar:write',
    title: 'Create a calendar feed',
    description: 'Creates a secret iCal address for your own calendar app (Apple Calendar, Google Calendar, Outlook), which shows what Foresi plans for you. The answer holds the address once, as address (https) and webcal; Foresi keeps only its hash, so it cannot be shown again. Give each app or device its own feed, so one can be revoked alone with foresi_revoke_calendar_feed.',
    status: 201,
    response: 'CalendarFeedCreated',
    staffOnly: true,
)]
final readonly class CreateFeedInput
{
    public function __construct(
        #[Field('What the feed is for, such as "Phone" or "Work laptop".', maxLength: CalendarFeed::NAME_MAX_LENGTH)]
        public string $name,
    ) {
    }
}
