<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

/**
 * The entries of the core itself. Modules and plugins add their own through
 * further NavigationItemProvider services.
 */
final readonly class CoreNavigation implements NavigationItemProvider
{
    public function items(): iterable
    {
        yield new NavigationItem('main', 'nav.item.dashboard', 'layout-grid', 'app_dashboard', weight: 10);
    }
}
