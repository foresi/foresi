<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * Money that is already a fact: a document issued or received, paid or not, or
 * a bank movement booked. The forecast never counts it; it tells which planned
 * occurrences it replaces (C13, C16). The amount is positive in its own
 * currency; the direction says which way it goes.
 *
 * @param string|null $counterpartyId      the contact of a document
 * @param string|null $counterpartyAccount the other account of a bank movement, as the bank gives it
 * @param string|null $variableSymbol      the variable symbol of a bank movement
 */
final readonly class ActualFlow
{
    public function __construct(
        public string $source,
        public FlowSubject $subject,
        public FlowDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $on,
        public ?string $counterpartyId = null,
        public ?string $counterpartyAccount = null,
        public ?string $variableSymbol = null,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('An actual flow has a positive amount; its direction says which way it goes.');
        }
    }
}
