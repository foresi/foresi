<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Navigation;

use App\Identity\Application\ExternalCheck;
use App\Identity\Infrastructure\Controller\SharedWithMeController;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The start page in the sidebar: the dashboard for the staff, what is shared
 * with them for an external person. Modules and plugins add their own entries
 * through further NavigationItemProvider services, each for the staff alone.
 */
final readonly class StartNavigation implements NavigationItemProvider
{
    public function __construct(private ExternalCheck $external)
    {
    }

    public function items(): iterable
    {
        if ($this->external->isExternal()) {
            yield new NavigationItem('main', 'nav.item.shared_with_me', 'share-2', SharedWithMeController::ROUTE, weight: 10);

            return;
        }
        yield new NavigationItem('main', 'nav.item.dashboard', 'layout-grid', 'app_dashboard', weight: 10);
    }
}
