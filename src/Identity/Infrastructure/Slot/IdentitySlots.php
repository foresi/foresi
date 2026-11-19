<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;

/**
 * What Identity adds to the account menu: API access, the language and signing out.
 */
final readonly class IdentitySlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            Slots::USER_MENU => [new SlotItem('@Identity/_user_menu.html.twig', weight: 20)],
            Slots::USER_MENU_END => [new SlotItem('@Identity/_user_menu_end.html.twig')],
            default => [],
        };
    }
}
