<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Margin\Infrastructure\Controller\MarginPageController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The margin in the sidebar, under Money after the VAT, for the owners and
 * the members: the margin is the firm's, so a guest never sees it.
 */
final readonly class MarginNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::MONEY, 'nav.item.margin', 'chart-column', MarginPageController::ROUTE, weight: 70, routePrefixes: [MarginPageController::CELL_ROUTE, MarginPageController::RANGE_ROUTE]);
        }
    }
}
