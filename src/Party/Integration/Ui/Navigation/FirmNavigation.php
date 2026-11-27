<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Navigation;

use Foresi\Shared\Application\ActorRole;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The settings of the instance's firm, for the owner.
 */
final readonly class FirmNavigation implements NavigationItemProvider
{
    public function __construct(private ActorRole $role)
    {
    }

    public function items(): iterable
    {
        if ($this->role->isOwner()) {
            yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.firm', 'building-2', 'app_firm', weight: 10, routePrefixes: ['app_firm', 'app_series'], section: CoreNavigationAreas::SECTION_FIRM);
        }
    }
}
