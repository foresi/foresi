<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\Suggestion;

/**
 * The suggestions on inbox items, each with its steps. Reads here know no
 * owner: the use cases check that the item is the current person's
 * (InboxAccess).
 */
interface SuggestionStore
{
    /** The suggestion with its steps; null for an unknown or malformed id. */
    public function find(string $id): ?Suggestion;

    /**
     * The item's suggestions, the oldest first.
     *
     * @return list<Suggestion>
     */
    public function forItem(InboxItem $item): array;

    /**
     * How many open (proposed) suggestions each item has.
     *
     * @param list<string> $itemIds
     *
     * @return array<string, int> by item id; an item without any is left out
     */
    public function openCounts(array $itemIds): array;

    /**
     * Locks the suggestion's row until the open transaction ends and reads it
     * and its steps again, so two decisions on one suggestion, such as two
     * accepts at once, run one after the other and the second sees the steps
     * the first made done.
     */
    public function lockForUpdate(Suggestion $suggestion): void;

    public function add(Suggestion $suggestion): void;

    public function remove(Suggestion $suggestion): void;
}
