<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;

/**
 * The inbox items and their outputs. Reads here know no owner but the one they
 * are given: the use cases check that the item is the current person's
 * (InboxAccess).
 */
interface ItemStore
{
    /** The item, trashed or not; null for an unknown or malformed id. */
    public function item(string $id): ?InboxItem;

    /** The item made of this stored mail message, if any. */
    public function itemOfMessage(string $mailMessageId): ?InboxItem;

    /**
     * A page of an owner's items outside the trash, the newest first (by id).
     *
     * @param ItemState|null $state               only the items in this state
     * @param bool           $unmatched           only the items with no contact
     * @param string|null    $afterId             the id the page starts after, from the previous page
     * @param bool|null      $withOpenSuggestions only the items with (true) or without (false) an open suggestion; null for both
     *
     * @return list<InboxItem> at most $limit
     */
    public function page(string $ownerUserId, ?ItemState $state, bool $unmatched, ?string $afterId, int $limit, ?bool $withOpenSuggestions = null): array;

    /**
     * How many items outside the trash an owner has in a state, counted up to a limit.
     */
    public function countInState(string $ownerUserId, ItemState $state, int $limit): int;

    /**
     * How many items outside the trash an owner has of each thread.
     *
     * @param list<string> $threadKeys
     *
     * @return array<string, int> by thread key; a key without items is left out
     */
    public function threadSizes(string $ownerUserId, array $threadKeys): array;

    /**
     * The owner's other items of the item's thread, outside the trash, the oldest first.
     *
     * @return list<InboxItem>
     */
    public function threadOf(InboxItem $item): array;

    /**
     * @return list<ItemOutput> the oldest first
     */
    public function outputs(InboxItem $item): array;

    /**
     * @param list<string> $itemIds
     *
     * @return array<string, int> how many outputs each item has; an item without any is left out
     */
    public function outputCounts(array $itemIds): array;

    /**
     * The items that went to the trash before the time, for good removal.
     *
     * @return list<InboxItem>
     */
    public function trashedBefore(\DateTimeImmutable $time): array;

    /**
     * Locks the item's row until the open transaction ends and reads it again,
     * so that two changes of one item, such as two actions at once, run one
     * after the other and the second sees the first's e-mail activity.
     */
    public function lock(InboxItem $item): void;

    public function add(InboxItem|ItemOutput $record): void;

    public function remove(InboxItem|ItemOutput $record): void;
}
