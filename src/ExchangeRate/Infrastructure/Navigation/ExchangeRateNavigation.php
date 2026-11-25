<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Navigation;

use Foresi\ExchangeRate\Infrastructure\Controller\ExchangeRateSettingsController;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Settings → Exchange rates, with the settings of the firm, for the owners
 * and the members: a guest never sees it.
 */
final readonly class ExchangeRateNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.exchange_rates', 'banknote', ExchangeRateSettingsController::ROUTE, weight: 44, routePrefixes: [ExchangeRateSettingsController::ROUTE], section: CoreNavigationAreas::SECTION_FIRM);
        }
    }
}
