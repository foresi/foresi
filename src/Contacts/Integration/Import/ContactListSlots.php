<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Integration\Import;

use Foresi\Contacts\Contract\ContactListActions;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * Import and export on the address book.
 */
final readonly class ContactListSlots implements SlotProvider
{
    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            ContactListActions::HEADER => [new SlotItem('@Contacts/import/_list_actions.html.twig')],
            ContactListActions::BULK => [new SlotItem('@Contacts/import/_bulk_export.html.twig')],
            default => [],
        };
    }
}
