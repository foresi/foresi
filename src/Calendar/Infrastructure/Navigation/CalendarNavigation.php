<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Navigation;

use Foresi\Calendar\Infrastructure\Controller\AccountCalendarController;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The calendar feeds among the connections of the person's own account, for the
 * staff only, as a guest has none. The address stays /account/calendar.
 */
final readonly class CalendarNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        yield new NavigationItem(NavigationArea::PROFILE, 'calendar.user_menu', 'calendar', AccountCalendarController::ROUTE, weight: 10, routePrefixes: [AccountCalendarController::ROUTE, 'app_calendar_feed_'], section: CoreNavigationAreas::SECTION_CONNECTIONS);
    }
}
