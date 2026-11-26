<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Infrastructure\Controller\ApiTokenController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * API and MCP among the connections of the person's own account, for the staff: the personal
 * tokens and how to connect a client. A guest has Connected apps there instead, their own OAuth
 * connections (ADR 0041). The addresses stay /settings/api and /connected-apps.
 */
final readonly class ApiTokenNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff, private GuestCheck $guest)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(NavigationArea::PROFILE, 'nav.item.api', 'key-round', ApiTokenController::ROUTE, weight: 30, routePrefixes: ['app_api_token', ApiTokenController::CONNECT_ROUTE], section: CoreNavigationAreas::SECTION_CONNECTIONS);
        } elseif ($this->guest->isGuest()) {
            yield new NavigationItem(NavigationArea::PROFILE, 'nav.item.connected_apps', 'key-round', ApiTokenController::GUEST_ROUTE, weight: 30, routePrefixes: ['app_connected_app'], section: CoreNavigationAreas::SECTION_CONNECTIONS);
        }
    }
}
