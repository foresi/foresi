<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Navigation;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The bank movements in the sidebar, for those who work with invoices, under
 * Finance as iDoklad has its Finance, Bank.
 */
final readonly class BankingNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('money', 'nav.item.bank', 'landmark', 'app_bank_movements', weight: 10, routePrefixes: ['app_bank_movement', 'app_bank_message'], area: CoreNavigationAreas::MONEY);
        }
    }
}
