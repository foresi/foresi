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
 * API and MCP in the settings, for the staff: the personal tokens and how to connect a client.
 * It is the one place for this access; the account menu has no entry of its own. A guest has
 * no settings: Connected apps, their own OAuth connections, is in the overview (ADR 0041).
 */
final readonly class ApiTokenNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff, private GuestCheck $guest)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.api', 'key-round', ApiTokenController::ROUTE, weight: 17, routePrefixes: ['app_api_token', ApiTokenController::CONNECT_ROUTE], section: CoreNavigationAreas::SECTION_CONNECTIONS);
        } elseif ($this->guest->isGuest()) {
            yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'nav.item.connected_apps', 'key-round', ApiTokenController::GUEST_ROUTE, weight: 40, routePrefixes: ['app_connected_app']);
        }
    }
}
