<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Infrastructure;

use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Vat\Infrastructure\Navigation\VatNavigation;

/**
 * The VAT in the sidebar under Money, after the taxes and contributions,
 * for the owners and the members; a guest never sees it.
 */
final class VatNavigationTest extends VatTestCase
{
    public function testTheStaffSeeTheItemAndAGuestDoesNot(): void
    {
        $navigation = self::getContainer()->get(VatNavigation::class);
        $item = new NavigationItem(CoreNavigationAreas::MONEY, 'nav.item.vat', 'percent', 'app_vat', weight: 60, routePrefixes: ['app_vat']);

        self::assertEquals([$item], [...$navigation->items()]);
        $this->signIn($this->owner);
        self::assertEquals([$item], [...$navigation->items()]);
        $this->signIn($this->guest);
        self::assertSame([], [...$navigation->items()]);
    }
}
