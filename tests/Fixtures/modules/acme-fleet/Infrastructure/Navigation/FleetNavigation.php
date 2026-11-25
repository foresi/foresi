<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Infrastructure\Navigation;

use Acme\Fleet\Infrastructure\Controller\VehicleController;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The fleet in the sidebar, under the firm's settings, for the owners and members.
 */
final readonly class FleetNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'acme_fleet.nav.vehicles', 'puzzle', VehicleController::ROUTE, weight: 90, routePrefixes: ['acme_fleet_']);
        }
    }
}
