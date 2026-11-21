<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The estimate of a year (Goal 1): "to date" from the actual amounts and the
 * months so far, and "year end" with the forecast, never mixed. A part that
 * cannot be computed is null with the reason why: year_not_started,
 * rules_not_available, or forecast_not_built until the forecast is read.
 * Every figure is an estimate, not a basis for the tax return.
 */
final readonly class EstimateView
{
    public const string TO_DATE_YEAR_NOT_STARTED = 'year_not_started';
    public const string TO_DATE_RULES_NOT_AVAILABLE = 'rules_not_available';
    public const string YEAR_END_FORECAST_NOT_BUILT = 'forecast_not_built';
    public const string WARNING_SOURCES_UNAVAILABLE = 'sources_unavailable';

    /**
     * @param list<string> $warnings
     * @param list<string> $unverified
     * @param list<string> $knownGaps
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
    ) {
    }
}
