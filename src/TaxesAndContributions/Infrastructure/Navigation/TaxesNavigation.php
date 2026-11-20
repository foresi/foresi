<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Navigation;

use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Infrastructure\Controller\ProfileController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Taxes and contributions in the sidebar, under Money after the planned
 * costs, for the owner only (B7): the owner profile holds personal data, so a
 * member never sees the item.
 */
final readonly class TaxesNavigation implements NavigationItemProvider
{
    public function __construct(private TaxesAccess $access)
    {
    }

    public function items(): iterable
    {
        if ($this->access->isOwner()) {
            yield new NavigationItem('money', 'nav.item.taxes', 'landmark', ProfileController::ROUTE, weight: 50, routePrefixes: ['app_taxes'], area: CoreNavigationAreas::MONEY);
        }
    }
}
