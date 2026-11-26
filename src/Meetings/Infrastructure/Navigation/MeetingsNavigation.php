<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Meetings\Infrastructure\Controller\MeetingController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Meetings in the Work area, after the projects, with the number of today's
 * meetings, for the owners and members; a guest sees no meeting.
 */
final readonly class MeetingsNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff, private MeetingsNavigationCount $count)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::PROJECTS, 'nav.item.meetings', 'square-pen', MeetingController::ROUTE, $this->count, weight: 20, commandLabelKey: 'meetings.meeting.new', commandRoute: 'app_meetings_meeting_new', routePrefixes: ['app_meetings_']);
        }
    }
}
