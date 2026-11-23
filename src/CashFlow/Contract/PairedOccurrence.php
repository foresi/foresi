<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The planned occurrence a document is paired with.
 */
final readonly class PairedOccurrence
{
    /**
     * @param \DateTimeImmutable $occurrenceOn the day of the occurrence in the schedule of the planned item
     * @param PairingState       $state        auto or confirmed
     */
    public function __construct(
        public string $plannedItemId,
        public \DateTimeImmutable $occurrenceOn,
        public PairingState $state,
    ) {
    }
}
