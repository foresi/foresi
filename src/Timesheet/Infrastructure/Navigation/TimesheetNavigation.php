<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Timesheet\Infrastructure\Controller\TimeWeekController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Time, the week and the report, in the projects of the sidebar after the
 * projects. It is the staff's: guests log none.
 */
final readonly class TimesheetNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::PROJECTS, 'nav.item.time', 'clock-3', TimeWeekController::ROUTE, weight: 30, routePrefixes: [TimeWeekController::ROUTE.'_report', TimeWeekController::ROUTE.'_new']);
        }
    }
}
