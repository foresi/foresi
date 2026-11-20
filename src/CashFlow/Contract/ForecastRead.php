<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The flows a ForecastQuery read, by the day they are counted on. It is
 * incomplete when a source failed or an amount had no rate to CZK today: what
 * failed is left out.
 */
final readonly class ForecastRead
{
    /**
     * @param list<ReadFlow> $flows              in the order the forecast counts them
     * @param list<string>   $unavailableSources the keys of the sources that failed
     * @param list<string>   $restrictedSources  the keys of the sources whose documents the caller may not read: their flows have no subject
     */
    public function __construct(
        public array $flows,
        public array $unavailableSources,
        public array $restrictedSources,
        public bool $incomplete,
    ) {
    }
}
