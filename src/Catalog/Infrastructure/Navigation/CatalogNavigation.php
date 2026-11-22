<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Navigation;

use Foresi\Catalog\Infrastructure\Controller\ProductController;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The products in the sidebar, under Sales after the invoicing, for the owners
 * and members, as HubSpot and Pipedrive keep their product library with sales.
 */
final readonly class CatalogNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('sales', 'nav.item.products', 'tag', ProductController::ROUTE, weight: 40, commandLabelKey: 'catalog.product.new', commandRoute: 'app_catalog_product_new', routePrefixes: ['app_catalog_product'], area: CoreNavigationAreas::SALES);
        }
    }
}
