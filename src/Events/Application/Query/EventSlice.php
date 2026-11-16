<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application\Query;

/**
 * A slice of the feed in ascending order of position. When more events follow,
 * the next slice starts after lastPosition.
 */
final readonly class EventSlice
{
    /**
     * @param list<EventRow> $rows
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
    ) {
    }

    /**
     * The position of the last event of the slice; null for an empty slice.
     */
    public function lastPosition(): ?int
    {
        return [] === $this->rows ? null : $this->rows[array_key_last($this->rows)]->position;
    }
}
