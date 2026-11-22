<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Activities;

use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Integration\Activities\ActivitiesContactTab;
use Foresi\Shared\Domain\Id;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tab is offered in the slot of a contact's tabs, for a contact, and in
 * no other slot or without one.
 */
final class ActivitiesTabSlotTest extends KernelTestCase
{
    public function testOfferedOnlyInTheSlotOfContactTabsForAContact(): void
    {
        $tab = self::getContainer()->get(ActivitiesContactTab::class);
        $id = Id::generate();

        self::assertCount(1, [...$tab->items(ContactTabs::SLOT, ['contactId' => $id])]);
        self::assertSame([], [...$tab->items('other.slot', ['contactId' => $id])]);
        self::assertSame([], [...$tab->items(ContactTabs::SLOT, [])]);
        self::assertSame([], [...$tab->items(ContactTabs::SLOT, ['contactId' => 7])]);
        self::assertSame([], [...$tab->items('other.slot', [])]);
    }
}
