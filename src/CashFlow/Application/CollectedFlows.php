<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

/**
 * What ForecastFlows collected from the sources: the lines to count, and the
 * sources that failed or whose documents the caller may not read.
 */
final readonly class CollectedFlows
{
    /**
     * @param list<ForecastLine> $lines       in the order they are counted
     * @param list<string>       $unavailable the keys of the sources and actual sources that failed, once each
     * @param list<string>       $restricted  the keys of the sources whose documents the caller may not read, once each
     * @param list<UndatedLine>  $undated     the undated flows, when they were asked for, the largest first
     */
    public function __construct(
        public array $lines,
        public array $unavailable,
        public array $restricted,
        public array $undated = [],
    ) {
    }
}
