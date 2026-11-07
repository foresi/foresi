<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The projects in the sidebar for the staff; a task belongs to them too.
 */
final readonly class WorkNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        yield new NavigationItem('work', 'nav.item.projects', 'columns-3', 'app_projects', weight: 10, routePrefixes: ['app_project', 'app_task']);
    }
}
