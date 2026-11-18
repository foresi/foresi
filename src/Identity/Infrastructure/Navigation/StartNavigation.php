<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Infrastructure\Controller\DashboardController;
use Foresi\Ui\Navigation\CoreNavigationAreas;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

/**
 * The start page in the sidebar: the dashboard for the staff, what is shared
 * with them for a guest. Modules and plugins add their own entries
 * through further NavigationItemProvider services, each for the staff alone.
 */
final readonly class StartNavigation implements NavigationItemProvider
{
    public function __construct(private GuestCheck $guest)
    {
    }

    public function items(): iterable
    {
        if ($this->guest->isGuest()) {
            yield new NavigationItem('main', 'nav.item.shared_with_me', 'share-2', DashboardController::GUEST_HOME, weight: 10, routePrefixes: ['app_project', 'app_task'], area: CoreNavigationAreas::OVERVIEW);

            return;
        }
        yield new NavigationItem('main', 'nav.item.dashboard', 'layout-grid', 'app_dashboard', weight: 10, area: CoreNavigationAreas::OVERVIEW);
    }
}
