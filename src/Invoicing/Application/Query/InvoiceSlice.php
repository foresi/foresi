<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

/**
 * A run of documents, newest first, and whether more follow it.
 */
final readonly class InvoiceSlice
{
    /**
     * @param list<InvoiceListItem> $items
     */
    public function __construct(public array $items, public bool $hasMore)
    {
    }

    /**
     * The id to ask the next slice after: the last of this one, when more follow.
     */
    public function nextAfterId(): ?string
    {
        if (!$this->hasMore || [] === $this->items) {
            return null;
        }

        return $this->items[array_key_last($this->items)]->row->id;
    }
}
