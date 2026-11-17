<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\CashFlow\Contract\FlowCategory;
use App\Party\Domain\Party;
use App\Shared\Domain\Money;
use App\Shared\Domain\Schedule\Recurrence;

/**
 * What a planned item says, checked already: a positive amount, a name, and
 * either a day or a schedule that starts on that day.
 */
final readonly class PlannedItemTerms
{
    public function __construct(
        public string $name,
        public FlowCategory $category,
        public Money $amount,
        public \DateTimeImmutable $firstOn,
        public ?Recurrence $recurrence,
        public ?Party $counterparty,
        public bool $included,
        public MatchRule $matchRule,
    ) {
        if ('' === trim($name) || $amount->minor() <= 0) {
            throw new \InvalidArgumentException('A planned item has a name and a positive amount.');
        }
    }
}
