<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One month of the accrual view of the year end (old T6): the profit the
 * month brings, as the records count it, the smallest advances of the month,
 * and the share of the year's levies it carries. The shares follow the profit
 * of each month, a month of low profit carries its smallest advances, and
 * the months together carry the levies of the year.
 */
final readonly class AccrualMonth
{
    public function __construct(
        public string $month,
        public Money $profit,
        public Money $minimum,
        public Money $levies,
    ) {
    }
}
