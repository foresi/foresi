<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * Invoices in the sidebar, for those who work with them.
 */
final readonly class InvoicingNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('sales', 'nav.item.invoices', 'receipt-text', 'app_invoices', weight: 10, commandLabelKey: 'invoice.new', commandRoute: 'app_invoice_new');
        }
    }
}
