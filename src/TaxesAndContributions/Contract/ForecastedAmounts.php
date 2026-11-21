<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * What a ForecastAmountSource read: the amounts expected, the sources that
 * failed and those whose records the caller may not read. It is incomplete
 * when something failed or had no rate to CZK; what failed is left out.
 */
final readonly class ForecastedAmounts
{
    /**
     * @param list<ForecastAmount> $amounts
     * @param list<string>         $unavailableSources
     * @param list<string>         $restrictedSources
     */
    public function __construct(
        public array $amounts,
        public array $unavailableSources,
        public array $restrictedSources,
        public bool $incomplete,
    ) {
    }
}
