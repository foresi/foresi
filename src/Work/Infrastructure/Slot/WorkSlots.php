<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Slot\SlotItem;
use Foresi\Ui\Slot\SlotProvider;
use Foresi\Ui\Slot\Slots;

/**
 * What Work adds to the shell: the running timer over every page of a member, and the
 * notification settings in the account menu.
 */
final readonly class WorkSlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            Slots::SHELL_WIDGETS => true === ($context['member'] ?? false) ? [new SlotItem('work/_running_timer.html.twig')] : [],
            Slots::USER_MENU => [new SlotItem('work/_user_menu.html.twig', weight: 10)],
            default => [],
        };
    }
}
