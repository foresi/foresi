<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;

/**
 * The item store of the container that notes each lock it is asked for, and does as it does.
 */
final readonly class LockRecordingItems implements ItemStore
{
    public function __construct(private ItemStore $inner, private LockLog $log)
    {
    }

    public function item(string $id): ?InboxItem
    {
        return $this->inner->item($id);
    }

    public function itemOfMessage(string $mailMessageId): ?InboxItem
    {
        return $this->inner->itemOfMessage($mailMessageId);
    }

    public function page(string $ownerUserId, ?ItemState $state, bool $unmatched, ?string $afterId, int $limit, ?bool $withOpenSuggestions = null): array
    {
        return $this->inner->page($ownerUserId, $state, $unmatched, $afterId, $limit, $withOpenSuggestions);
    }

    public function countInState(string $ownerUserId, ItemState $state, int $limit): int
    {
        return $this->inner->countInState($ownerUserId, $state, $limit);
    }

    public function threadSizes(string $ownerUserId, array $threadKeys): array
    {
        return $this->inner->threadSizes($ownerUserId, $threadKeys);
    }

    public function threadOf(InboxItem $item): array
    {
        return $this->inner->threadOf($item);
    }

    public function outputs(InboxItem $item): array
    {
        return $this->inner->outputs($item);
    }

    public function outputCounts(array $itemIds): array
    {
        return $this->inner->outputCounts($itemIds);
    }

    public function trashedBefore(\DateTimeImmutable $time): array
    {
        return $this->inner->trashedBefore($time);
    }

    public function lock(InboxItem $item): void
    {
        $this->log->locks[] = 'item '.$item->id();
        $this->inner->lock($item);
    }

    public function add(InboxItem|ItemOutput $record): void
    {
        $this->inner->add($record);
    }

    public function remove(InboxItem|ItemOutput $record): void
    {
        $this->inner->remove($record);
    }
}
