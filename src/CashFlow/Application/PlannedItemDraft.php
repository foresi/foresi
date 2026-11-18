<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

/**
 * A planned item as a client sent it, merged with what it said before for a
 * partial change, before the rules check it.
 */
final readonly class PlannedItemDraft
{
    public function __construct(
        public string $name,
        public string $category,
        public string $amount,
        public string $currency,
        public string $on,
        public ?string $repeat,
        public int $every,
        public bool $lastDayOfMonth,
        public ?string $endsOn,
        public ?int $endsAfter,
        public ?string $counterpartyId,
        public bool $included,
        public ?string $matchAccount,
        public ?string $matchVariableSymbol,
        public ?string $matchTolerance,
    ) {
    }
}
