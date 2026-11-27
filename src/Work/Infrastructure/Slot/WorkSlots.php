<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;

/**
 * What Work adds to the shell: the notification settings in the account menu.
 */
final readonly class WorkSlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            Slots::USER_MENU => [new SlotItem('@Work/_user_menu.html.twig', weight: 10)],
            default => [],
        };
    }
}
