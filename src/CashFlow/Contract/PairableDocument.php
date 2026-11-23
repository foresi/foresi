<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A received document as Cash flow needs it to find the planned occurrence it
 * belongs to.
 */
final readonly class PairableDocument
{
    /**
     * @param Money              $amount the total of the document in its currency, positive
     * @param \DateTimeImmutable $on     the tax point, or the issue date when it has none. It is a day in the instance's zone:
     *                                   an instant is converted to that zone and its date there counts, so 23:30 UTC on 30 November
     *                                   is 1 December in Prague; a date created in the instance's zone stays the same day
     */
    public function __construct(
        public FlowSubject $subject,
        public ?string $counterpartyId,
        public FlowDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $on,
    ) {
    }
}
