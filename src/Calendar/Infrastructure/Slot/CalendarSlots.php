<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;

/**
 * What Calendar adds to the shell: the calendar feeds in the account menu, next to the notification settings.
 */
final readonly class CalendarSlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return Slots::USER_MENU === $slot ? [new SlotItem('@Calendar/_user_menu.html.twig', weight: 15)] : [];
    }
}
