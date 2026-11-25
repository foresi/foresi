<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;
use Foresi\Vat\Infrastructure\Controller\VatController;

/**
 * The VAT in the sidebar, under Money after the taxes and contributions, for
 * the owners and the members: the VAT is the firm's, so a guest never sees it.
 */
final readonly class VatNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::MONEY, 'nav.item.vat', 'percent', VatController::ROUTE, weight: 60, routePrefixes: [VatController::ROUTE]);
        }
    }
}
