<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Navigation;

use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Infrastructure\Controller\TaxesController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Taxes and contributions in the sidebar, under Money after the planned
 * costs, for the owner only (B7): the page and the owner profile under it
 * hold personal data, so a member never sees the item.
 */
final readonly class TaxesNavigation implements NavigationItemProvider
{
    public function __construct(private TaxesAccess $access)
    {
    }

    public function items(): iterable
    {
        if ($this->access->isOwner()) {
            yield new NavigationItem(CoreNavigationAreas::MONEY, 'nav.item.taxes', 'landmark', TaxesController::ROUTE, weight: 50, routePrefixes: ['app_taxes']);
        }
    }
}
