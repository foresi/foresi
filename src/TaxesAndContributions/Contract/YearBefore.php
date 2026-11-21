<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The year before the one computed, as a regime needs it when the expense
 * method changes between the two: what was paid in it and what was still
 * open at its last day.
 */
final readonly class YearBefore
{
    public function __construct(
        public int $year,
        public AmountsView $amounts,
        public OpenAmountsView $open,
    ) {
    }
}
