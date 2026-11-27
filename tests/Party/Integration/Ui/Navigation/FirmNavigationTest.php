<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Ui\Navigation;

use Foresi\Party\Integration\Ui\Navigation\FirmNavigation;
use Foresi\Shared\Application\ActorRole;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use PHPUnit\Framework\TestCase;

final class FirmNavigationTest extends TestCase
{
    public function testAnOwnerFindsTheFirmFirstInTheFirmSectionOfTheSettings(): void
    {
        $role = self::createStub(ActorRole::class);
        $role->method('isOwner')->willReturn(true);

        self::assertEquals(
            [new NavigationItem(NavigationArea::SETTINGS, 'nav.item.firm', 'building-2', 'app_firm', weight: 10, routePrefixes: ['app_firm', 'app_series'], section: CoreNavigationAreas::SECTION_FIRM)],
            [...new FirmNavigation($role)->items()],
        );
    }

    public function testNobodyElseFindsIt(): void
    {
        self::assertSame([], [...new FirmNavigation(self::createStub(ActorRole::class))->items()]);
    }
}
