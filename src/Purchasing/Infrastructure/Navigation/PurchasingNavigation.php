<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Navigation;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The received documents in the sidebar, under Finance next to the bank, as
 * iDoklad keeps its received documents and Fakturoid its costs; the number is
 * the drafts that wait to be completed. Only the staff see it.
 */
final readonly class PurchasingNavigation implements NavigationItemProvider
{
    public const string ROUTE = 'app_purchasing';

    public function __construct(private StaffCheck $staff, private DraftsNavigationCount $drafts)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::PURCHASING, 'nav.item.purchasing', 'file-text', self::ROUTE, $this->drafts, weight: 20, routePrefixes: [self::ROUTE]);
        }
    }
}
