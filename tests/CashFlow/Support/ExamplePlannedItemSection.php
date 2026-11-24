<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\CashFlowSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * Test double of a module that adds a section to the page of a planned item: while switched on, it answers
 * CashFlowSlots::PLANNED_ITEM_SECTIONS with one panel. Test env only.
 */
final class ExamplePlannedItemSection implements SlotProvider
{
    public static bool $enabled = false;

    public function items(string $slot, array $context): iterable
    {
        if (!self::$enabled || CashFlowSlots::PLANNED_ITEM_SECTIONS !== $slot || !\is_string($context['plannedItemId'] ?? null)) {
            return [];
        }

        return [new SlotItem('@CashFlowTestSupport/planned_item_section.html.twig', ['label' => 'Example section'], 10)];
    }
}
