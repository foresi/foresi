<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One actual amount with its CZK value at the rate of the day it was paid,
 * and what the records count of it.
 */
final readonly class AmountLine
{
    public function __construct(
        public ActualAmount $amount,
        public Money $grossCzk,
        public Money $netCzk,
        public Money $vatCzk,
        public Money $countedCzk,
    ) {
    }
}
