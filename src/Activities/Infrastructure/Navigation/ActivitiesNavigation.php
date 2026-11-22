<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Navigation;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Infrastructure\Controller\ActivitySettingsController;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The activity types under Settings. Activities have no list of their own in
 * this slice: they are on the timelines of records, and "my planned" is in the
 * calendar feed and the API. For the owners and members only.
 */
final readonly class ActivitiesNavigation implements NavigationItemProvider
{
    public function __construct(private ActivitiesAccess $access)
    {
    }

    public function items(): iterable
    {
        if (!$this->access->isStaff()) {
            return;
        }
        yield new NavigationItem('settings', 'nav.item.activity_settings', 'calendar', ActivitySettingsController::ROUTE, weight: 45, routePrefixes: [ActivitySettingsController::ROUTE], area: NavigationArea::SETTINGS);
    }
}
