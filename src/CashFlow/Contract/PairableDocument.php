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
     * @param \DateTimeImmutable $on     the tax point, or the issue date when it has none
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
