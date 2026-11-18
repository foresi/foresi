<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Infrastructure\Controller\PeopleController;
use Foresi\Ui\Navigation\NavigationArea;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

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
            yield new NavigationItem('settings', 'nav.item.people', 'user', PeopleController::ROUTE, weight: 5, routePrefixes: ['app_people'], area: NavigationArea::SETTINGS);
        }
    }
}
