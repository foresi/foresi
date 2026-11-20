<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What a module asks of the forecast: the days, the sources and categories it
 * leaves out, and the certainties it wants.
 */
final readonly class ForecastQuery
{
    /**
     * @param \DateTimeImmutable       $from              the first day, today or later
     * @param \DateTimeImmutable       $to                the last day, inclusive, within 24 months of today
     * @param list<string>             $excludeSources    the keys of the sources never to call
     * @param list<FlowCategory>       $excludeCategories the categories to leave out
     * @param list<FlowCertainty>|null $certainties       the certainties to keep; null keeps them all
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $excludeSources = [],
        public array $excludeCategories = [],
        public ?array $certainties = null,
    ) {
    }
}
