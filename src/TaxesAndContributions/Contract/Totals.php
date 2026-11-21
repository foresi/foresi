<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The figures of one expense method, in CZK: the income, the real costs, the
 * expenses claimed (the real costs or the lump sum), the profit, each levy,
 * the child bonus paid out, the levies together and what the owner keeps:
 * income - real costs - levies + child bonus. The social base is what the
 * pension counts from. In a year with flat-rate months, social, health and
 * income tax are those of the other months, the flat rate is the bands'
 * amounts of its months, and the levies add it.
 */
final readonly class Totals
{
    public function __construct(
        public Money $income,
        public Money $costs,
        public Money $expenses,
        public Money $profit,
        public Money $social,
        public Money $health,
        public Money $sickness,
        public Money $incomeTax,
        public Money $childBonus,
        public Money $levies,
        public Money $takeHome,
        public Money $socialBase,
        public Money $flatRate,
    ) {
    }
}
