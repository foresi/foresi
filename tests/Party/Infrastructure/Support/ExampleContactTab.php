<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Support;

use Foresi\Party\Contract\ContactTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * Test double of a module that adds a tab to a contact: while switched on, it
 * answers ContactTabs::SLOT with one tab whose page is under /example-tab. Test
 * env only.
 */
final class ExampleContactTab implements SlotProvider
{
    public static bool $enabled = false;

    public function items(string $slot, array $context): iterable
    {
        if (!self::$enabled || ContactTabs::SLOT !== $slot || !\is_string($context['contactId'] ?? null)) {
            return [];
        }

        return [new SlotItem(data: ['key' => 'deals', 'label' => 'Deals', 'href' => '/example-tab/'.$context['contactId']], weight: 100)];
    }
}
