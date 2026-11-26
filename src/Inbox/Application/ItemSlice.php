<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * A page of the person's inbox, the newest first.
 */
final readonly class ItemSlice
{
    /**
     * @param list<ItemRow> $rows
     */
    public function __construct(public array $rows, public bool $hasMore)
    {
    }

    /** The id the next page starts after; null when nothing follows. */
    public function nextAfter(): ?string
    {
        $last = $this->rows[\count($this->rows) - 1] ?? null;

        return $this->hasMore && $last instanceof ItemRow ? $last->item->id() : null;
    }
}
