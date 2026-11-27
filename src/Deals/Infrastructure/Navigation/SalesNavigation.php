<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Navigation;

use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Infrastructure\Controller\DealController;
use Foresi\Deals\Infrastructure\Controller\SalesSettingsController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The deals in the sidebar, first under Sales before the documents and the
 * products, and the stages and lost reasons under Settings, for the owners
 * and members only.
 */
final readonly class SalesNavigation implements NavigationItemProvider
{
    public function __construct(private SalesAccess $access)
    {
    }

    public function items(): iterable
    {
        if (!$this->access->mayWork()) {
            return;
        }
        yield new NavigationItem(CoreNavigationAreas::SALES, 'nav.item.deals', 'trending-up', DealController::ROUTE, weight: 10, commandLabelKey: 'deals.deal.new', commandRoute: DealController::NEW_ROUTE, routePrefixes: ['app_deal'], section: CoreNavigationAreas::SECTION_SELLING);
        yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.deals_settings', 'trending-up', SalesSettingsController::ROUTE, weight: 50, routePrefixes: ['app_deal_settings', 'app_deal_stage_', 'app_deal_lost_reason'], section: CoreNavigationAreas::SECTION_FIRM);
    }
}
