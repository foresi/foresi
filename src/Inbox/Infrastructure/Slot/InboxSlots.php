<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;

/**
 * "Inbox addresses" in the account menu, next to the calendar feeds.
 */
final readonly class InboxSlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return Slots::USER_MENU === $slot ? [new SlotItem('@Inbox/_user_menu.html.twig', weight: 16)] : [];
    }
}
