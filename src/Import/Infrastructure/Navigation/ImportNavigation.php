<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The history of imports, under Settings. A new import still starts from the page
 * of the records it fills, and from the button on the history.
 */
final readonly class ImportNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('settings', 'nav.item.import', 'upload', 'app_imports', weight: 20, routePrefixes: ['app_import']);
        }
    }
}
