<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Integration\Ui\Controller\PeopleController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The people of the organisation in the settings, for the staff.
 */
final readonly class PeopleNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.people', 'user', PeopleController::ROUTE, weight: 12, routePrefixes: ['app_people'], section: CoreNavigationAreas::SECTION_FIRM);
        }
    }
}
