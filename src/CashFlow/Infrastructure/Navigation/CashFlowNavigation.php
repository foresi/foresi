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
 * planner with the bank, and after it the planned income and the planned
 * costs.
 */
final readonly class CashFlowNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            // The cash flow's own routes, and not the planned items' pages, which have items of their own.
            yield new NavigationItem('money', 'nav.item.cash_flow', 'trending-up', CashFlowController::ROUTE, weight: 20, routePrefixes: [CashFlowController::ROUTE.'_items', CashFlowController::ROUTE.'_balance', CashFlowController::ROUTE.'_minimum'], area: CoreNavigationAreas::MONEY);
            // Planned money in and out, after the Cash flow, as P1 of the approved design (2026-11-19).
            yield new NavigationItem('money', 'nav.item.planned_income', 'arrow-down', 'app_cash_flow_planned_income', weight: 30, routePrefixes: ['app_cash_flow_planned_income'], area: CoreNavigationAreas::MONEY);
            yield new NavigationItem('money', 'nav.item.planned_costs', 'arrow-up', 'app_cash_flow_planned_costs', weight: 40, routePrefixes: ['app_cash_flow_planned_costs'], area: CoreNavigationAreas::MONEY);
        }
    }
}
