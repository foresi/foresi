<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Ui\Navigation\NavigationArea;
use Foresi\Ui\Navigation\NavigationAreaProvider;
use Foresi\Ui\Navigation\NavigationCountProvider;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

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
