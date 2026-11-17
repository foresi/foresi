<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

/**
 * Received documents newest first, and whether more follow.
 */
final readonly class ReceivedDocumentSlice
{
    /**
     * @param list<PayableDocument> $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {
    }

    public function lastId(): ?string
    {
        return [] === $this->items ? null : $this->items[array_key_last($this->items)]->document->id();
    }
}
