<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Identity\Infrastructure\Controller\ApiTokenController;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The personal API tokens in the settings, for the staff.
 */
final readonly class ApiTokenNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('settings', 'nav.item.api', 'key-round', ApiTokenController::ROUTE, weight: 17, routePrefixes: ['app_api_token']);
        }
    }
}
