<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A planned item a person may pair a document with, and the days it falls on near the document, the days to choose from.
 */
final readonly class PairingChoice
{
    /**
     * @param list<\DateTimeImmutable> $occurrences one or more, oldest first
     */
    public function __construct(
        public string $plannedItemId,
        public string $name,
        public array $occurrences,
    ) {
    }
}
