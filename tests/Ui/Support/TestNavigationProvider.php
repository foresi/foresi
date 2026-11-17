<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Support;

use App\Ui\Navigation\NavigationArea;
use App\Ui\Navigation\NavigationAreaProvider;
use App\Ui\Navigation\NavigationCountProvider;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * Stands in for a plugin: registers an area of its own and an item in it, in
 * the Extensions group (test env only).
 */
final readonly class TestNavigationProvider implements NavigationItemProvider, NavigationAreaProvider
{
    public const string AREA = 'extensions';

    public function areas(): iterable
    {
        yield new NavigationArea(self::AREA, 'Extensions', 'Plugins of this installation', 'puzzle', 900);
    }

    public function items(): iterable
    {
        yield new NavigationItem('extensions', 'Pergola configurator', 'puzzle', 'app_people_invitations', new class implements NavigationCountProvider {
            public function count(): int
            {
                return 4;
            }
        }, area: self::AREA);
    }
}
