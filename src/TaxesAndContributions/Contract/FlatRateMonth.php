<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One month in a flat-rate regime: its band and the monthly amount of the
 * band split into the income tax, social and health parts it replaces.
 */
final readonly class FlatRateMonth
{
    public function __construct(
        public string $month,
        public string $band,
        public Money $incomeTax,
        public Money $social,
        public Money $health,
    ) {
    }

    public function total(): Money
    {
        return $this->incomeTax->plus($this->social)->plus($this->health);
    }
}
