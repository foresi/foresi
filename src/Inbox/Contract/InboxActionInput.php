<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * The input of an operation declared as an InboxAction: which item it acts on,
 * whether the item is marked done after it, and the further records the item's
 * e-mail activity is linked to besides the record it made.
 */
interface InboxActionInput
{
    /**
     * The item the action acts on; null when the operation runs without an item, so Inbox records nothing.
     */
    public function inboxItemId(): ?string;

    /**
     * Whether the item is marked done once the action has made its record (the field markDone, true by default).
     */
    public function markDone(): bool;

    /**
     * Further records the e-mail activity is linked to besides the made record, such as the project of a new task.
     *
     * @return list<SuggestedRecord>
     */
    public function relatedRecords(): array;
}
