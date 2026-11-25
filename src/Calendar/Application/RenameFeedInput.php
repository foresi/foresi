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
    name: 'calendar.feeds.rename',
    method: 'PATCH',
    path: '/api/v1/calendar/feeds/{feedId}',
    tool: 'foresi_rename_calendar_feed',
    scope: 'calendar:write',
    title: 'Rename a calendar feed',
    description: 'Renames one of your calendar feeds; its address stays the same. The name is also the calendar\'s name in the calendar app after its next refresh. Find the id with foresi_list_calendar_feeds.',
    response: 'CalendarFeed',
    staffOnly: true,
)]
final readonly class RenameFeedInput
{
    public function __construct(
        #[Field('The id of the feed.', format: 'uuid')]
        public string $feedId,
        #[Field('The new name, such as "Phone".', maxLength: CalendarFeed::NAME_MAX_LENGTH)]
        public string $name,
    ) {
    }
}
