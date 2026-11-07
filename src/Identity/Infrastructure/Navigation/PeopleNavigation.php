<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Identity\Infrastructure\Controller\PeopleController;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

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
            yield new NavigationItem('settings', 'nav.item.people', 'user', PeopleController::ROUTE, weight: 5, routePrefixes: ['app_people']);
        }
    }
}
