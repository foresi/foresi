<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The occurrence of a planned item a cost document is paired with.
 */
final readonly class CostPairing
{
    /**
     * @param \DateTimeImmutable $occurrenceOn the calendar day of the occurrence, in the instance's days
     */
    public function __construct(
        public string $plannedItemId,
        public \DateTimeImmutable $occurrenceOn,
    ) {
    }

    /**
     * The first day of the occurrence's month: the month of the cost.
     */
    public function month(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurrenceOn->format('Y-m-01'));
    }

    public function day(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurrenceOn->format('Y-m-d'));
    }
}
