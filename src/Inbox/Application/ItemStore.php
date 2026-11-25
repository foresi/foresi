<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;

/**
 * The inbox items and their outputs. Reads here know no owner: the use cases
 * check that the item is the current person's.
 */
interface ItemStore
{
    /** The item, trashed or not; null for an unknown or malformed id. */
    public function item(string $id): ?InboxItem;

    /** The item made of this stored mail message, if any. */
    public function itemOfMessage(string $mailMessageId): ?InboxItem;

    public function add(InboxItem|ItemOutput $record): void;
}
