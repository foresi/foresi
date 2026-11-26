<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\Suggestion;

/**
 * The suggestion store of the container that notes each lock it is asked for, and does as it does.
 */
final readonly class LockRecordingSuggestions implements SuggestionStore
{
    public function __construct(private SuggestionStore $inner, private LockLog $log)
    {
    }

    public function find(string $id): ?Suggestion
    {
        return $this->inner->find($id);
    }

    public function forItem(InboxItem $item): array
    {
        return $this->inner->forItem($item);
    }

    public function openCounts(array $itemIds): array
    {
        $this->log->counted[] = $itemIds;

        return $this->inner->openCounts($itemIds);
    }

    public function lockForUpdate(Suggestion $suggestion): void
    {
        $this->log->locks[] = 'suggestion '.$suggestion->id();
        $this->inner->lockForUpdate($suggestion);
    }

    public function add(Suggestion $suggestion): void
    {
        $this->inner->add($suggestion);
    }

    public function remove(Suggestion $suggestion): void
    {
        $this->inner->remove($suggestion);
    }
}
