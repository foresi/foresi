<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A document paired with an occurrence of a planned item, and the occurrence it is paired with.
 */
final readonly class PairedSubject
{
    /**
     * @param \DateTimeImmutable $occurrenceOn the day of the occurrence in the schedule of the planned item
     */
    public function __construct(
        public FlowSubject $subject,
        public \DateTimeImmutable $occurrenceOn,
    ) {
    }
}
