<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Ui\Navigation\CoreNavigationAreas;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

/**
 * The start page in the sidebar: the dashboard, for members who are not guests.
 * A guest's start page comes from the module that offers it (Identity\Contract\GuestHome).
 * Modules and plugins add their own entries through further NavigationItemProvider services.
 */
final readonly class StartNavigation implements NavigationItemProvider
{
    public function __construct(private GuestCheck $guest)
    {
    }

    public function items(): iterable
    {
        if ($this->guest->isGuest()) {
            return;
        }
        yield new NavigationItem('main', 'nav.item.dashboard', 'layout-grid', 'app_dashboard', weight: 10, area: CoreNavigationAreas::OVERVIEW);
    }
}
