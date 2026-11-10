<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

/**
 * A slice of the address book, newest first, and whether more follows it. The
 * next slice starts after the id of the last row.
 */
final readonly class ContactSlice
{
    /**
     * @param list<ContactRow> $rows
     */
    public function __construct(public array $rows, public bool $hasMore)
    {
    }

    public function lastId(): ?string
    {
        return [] === $this->rows ? null : $this->rows[\count($this->rows) - 1]->id;
    }
}
