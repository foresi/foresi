<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * One page of the address book list.
 */
final readonly class PartyPage
{
    /**
     * @param list<PartyRow>     $rows
     * @param array<string, int> $counts the count of each view the list shows one for, by its key
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $pages,
        public array $counts = [],
    ) {
    }
}
