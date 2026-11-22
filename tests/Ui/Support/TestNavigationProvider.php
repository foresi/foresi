<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationAreaProvider;
use Foresi\Ui\Contract\Navigation\NavigationCountProvider;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Stands in for a plugin: registers an area of its own and an item in it
 * (test env only).
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
        yield new NavigationItem(self::AREA, 'Pergola configurator', 'puzzle', 'app_people_invitations', new class implements NavigationCountProvider {
            public function count(): int
            {
                return 4;
            }
        });
    }
}
