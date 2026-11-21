<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The estimate of a year (Goal 1): "to date" from the actual amounts and the
 * months so far, and "year end", which adds the forecast to 31 December and
 * counts every month, never mixed. The forecast part is kept apart, split by
 * certainty, and the accruals spread the year end's levies over its months.
 * A part that cannot be computed is null with the reason why:
 * year_not_started, rules_not_available, cash_flow_not_installed or
 * forecast_unavailable. A past year has no forecast: its year end is what
 * was paid. Every figure is an estimate, not a basis for the tax return.
 */
final readonly class EstimateView
{
    public const string TO_DATE_YEAR_NOT_STARTED = 'year_not_started';
    public const string TO_DATE_RULES_NOT_AVAILABLE = 'rules_not_available';
    public const string YEAR_END_CASH_FLOW_NOT_INSTALLED = 'cash_flow_not_installed';
    public const string YEAR_END_FORECAST_UNAVAILABLE = 'forecast_unavailable';
    public const string WARNING_SOURCES_UNAVAILABLE = 'sources_unavailable';
    public const string WARNING_FORECAST_INCOMPLETE = 'forecast_incomplete';

    /**
     * @param list<string>                      $warnings
     * @param list<string>                      $unverified
     * @param list<string>                      $knownGaps
     * @param array<string, list<AccrualMonth>> $accruals   of the year end, by the value of the expense method
     */
    public function __construct(
        public int $year,
        public \DateTimeImmutable $today,
        public ?RegimeResult $toDate,
        public ?string $toDateReason,
        public ?RegimeResult $yearEnd,
        public ?string $yearEndReason,
        public ?AmountsView $amounts,
        public array $warnings,
        public array $unverified,
        public array $knownGaps,
        public ?ForecastView $forecast = null,
        public array $accruals = [],
    ) {
    }
}
