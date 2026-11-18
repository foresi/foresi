<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ForecastPeriod;
use Foresi\Shared\Domain\Money;

/**
 * One period of the forecast with its balances and its lines, in CZK.
 */
final readonly class PeriodForecast
{
    /**
     * @param array<string, Money> $inflows  by category
     * @param array<string, Money> $outflows by category
     * @param list<ForecastLine>   $lines    in the order they are counted, those the caller may read
     * @param list<HiddenTotal>    $hidden   the lines of the sources the caller may not read, summed
     */
    public function __construct(
        public ForecastPeriod $period,
        public Money $opening,
        public array $inflows,
        public array $outflows,
        public Money $closing,
        public array $lines,
        public array $hidden = [],
    ) {
    }
}
