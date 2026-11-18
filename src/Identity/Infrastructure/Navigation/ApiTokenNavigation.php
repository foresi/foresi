<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Infrastructure\Controller\ApiTokenController;
use Foresi\Ui\Navigation\NavigationArea;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

/**
 * API and MCP in the settings, for the staff: the personal tokens and how to connect a client.
 * It is the one place for this access; the account menu has no entry of its own.
 */
final readonly class ApiTokenNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem('settings', 'nav.item.api', 'key-round', ApiTokenController::ROUTE, weight: 17, routePrefixes: ['app_api_token', ApiTokenController::CONNECT_ROUTE], area: NavigationArea::SETTINGS);
        }
    }
}
