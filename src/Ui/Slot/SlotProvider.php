<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Slot;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that fills screen slots of another module: firm tabs, user-menu items, shell widgets.
 * The screen that offers the slot never links to the provider's routes, so the provider can be absent.
 */
#[AutoconfigureTag(Slots::TAG)]
interface SlotProvider
{
    /**
     * @param array<string, mixed> $context what the screen passes to the slot
     *
     * @return iterable<SlotItem> nothing for a slot the provider does not fill
     */
    public function items(string $slot, array $context): iterable;
}
