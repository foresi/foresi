<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The spouse the owner claims in a year: the months, whether they hold a
 * severe disability card (ZTP/P), and whether their own income stays at most
 * 68,000 CZK.
 */
final readonly class SpouseInput
{
    public function __construct(
        public int $months,
        public bool $ztpP,
        public bool $lowIncome,
    ) {
    }
}
