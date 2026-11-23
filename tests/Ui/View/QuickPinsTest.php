<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\View;

use Foresi\Ui\View\QuickPins;
use PHPUnit\Framework\TestCase;

/**
 * The quick filters of a list without saved views: its own by default, others from the address.
 */
final class QuickPinsTest extends TestCase
{
    private const array FIELDS = ['kind' => 'Kind', 'city' => 'City', 'roles' => 'Roles'];

    public function testThePageShowsItsOwnPinsFirstThenTheOthersToPin(): void
    {
        $pins = QuickPins::read([], self::FIELDS, ['roles', 'kind']);

        self::assertSame(['roles', 'kind'], $pins->pinned);
        self::assertSame([
            ['key' => 'roles', 'label' => 'Roles', 'pinned' => true],
            ['key' => 'kind', 'label' => 'Kind', 'pinned' => true],
            ['key' => 'city', 'label' => 'City', 'pinned' => false],
        ], $pins->pinnable);
        self::assertFalse($pins->modified);
        self::assertSame([], $pins->query);
    }

    public function testTheAddressPinsOthersAndKeepsThem(): void
    {
        $pins = QuickPins::read(['pins' => 'city,gone'], self::FIELDS, ['roles', 'kind']);

        self::assertSame(['city'], $pins->pinned);
        self::assertSame([
            ['key' => 'city', 'label' => 'City', 'pinned' => true],
            ['key' => 'kind', 'label' => 'Kind', 'pinned' => false],
            ['key' => 'roles', 'label' => 'Roles', 'pinned' => false],
        ], $pins->pinnable);
        self::assertTrue($pins->modified);
        self::assertSame(['pins' => 'city'], $pins->query);
    }

    public function testAnEmptyPinsPinsNoneAndThePagesOwnAreNoChange(): void
    {
        $none = QuickPins::read(['pins' => ''], self::FIELDS, ['roles']);
        self::assertSame([], $none->pinned);
        self::assertTrue($none->modified);
        self::assertSame(['pins' => ''], $none->query);

        $own = QuickPins::read(['pins' => 'roles'], self::FIELDS, ['roles']);
        self::assertSame(['roles'], $own->pinned);
        self::assertFalse($own->modified);
        self::assertSame([], $own->query);

        $notAList = QuickPins::read(['pins' => 7], self::FIELDS, ['roles']);
        self::assertSame([], $notAList->pinned, 'Pins that name nothing pin none.');
        self::assertTrue($notAList->modified);
    }
}
