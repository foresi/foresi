<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Navigation;

use Foresi\CashFlow\Infrastructure\Controller\CashFlowController;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The cash flow in the sidebar, under Money next to the bank, for those who
 * work with the firm's money, as Xero and QuickBooks keep their cash flow
 * planner with the bank.
 */
final readonly class CashFlowNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('money', 'nav.item.cash_flow', 'trending-up', CashFlowController::ROUTE, weight: 20, routePrefixes: [CashFlowController::ROUTE], area: CoreNavigationAreas::MONEY);
        }
    }
}
