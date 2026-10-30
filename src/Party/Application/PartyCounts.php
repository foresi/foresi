<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * The numbers above the address book list. Everything except "archived"
 * counts active parties only.
 */
final readonly class PartyCounts
{
    public function __construct(
        public int $all,
        public int $customers,
        public int $suppliers,
        public int $unreliablePayers,
        public int $archived,
    ) {
    }
}
