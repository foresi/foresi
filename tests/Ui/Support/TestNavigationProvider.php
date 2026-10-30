<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Support;

use App\Ui\Navigation\NavigationCountProvider;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * Stands in for a plugin: registers an item in the Extensions group (test env only).
 */
final readonly class TestNavigationProvider implements NavigationItemProvider
{
    public function items(): iterable
    {
        yield new NavigationItem('extensions', 'Pergola configurator', 'puzzle', 'app_organisation_choose', new class implements NavigationCountProvider {
            public function count(): int
            {
                return 4;
            }
        });
    }
}
