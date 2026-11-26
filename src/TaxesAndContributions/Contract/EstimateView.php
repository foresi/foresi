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
 * was paid. Next year has nothing to date; its year end is the forecast of
 * all its months. The payments are those of the year as they fall due, each
 * expected, paid by a bank movement or overdue (B4), with the planned items of
 * Cash flow that may count them twice (B5). Every figure is an estimate, not
 * a basis for the tax return.
 */
final readonly class EstimateView
{
    public const string TO_DATE_YEAR_NOT_STARTED = 'year_not_started';
    public const string TO_DATE_RULES_NOT_AVAILABLE = 'rules_not_available';
    public const string YEAR_END_CASH_FLOW_NOT_INSTALLED = 'cash_flow_not_installed';
    public const string YEAR_END_FORECAST_UNAVAILABLE = 'forecast_unavailable';
    public const string WARNING_SOURCES_UNAVAILABLE = 'sources_unavailable';
    public const string WARNING_FORECAST_INCOMPLETE = 'forecast_incomplete';
    public const string WARNING_POSSIBLE_DUPLICATES = 'possible_duplicates';
    public const string WARNING_NO_MATCH_RULE = 'no_match_rule';
    public const string WARNING_PAYMENTS_UNAVAILABLE = 'payments_unavailable';

    /**
     * @param list<string>                      $warnings
     * @param list<string>                      $unverified
     * @param list<string>                      $knownGaps
     * @param array<string, list<AccrualMonth>> $accruals           of the year end, by the value of the expense method
     * @param list<DuePayment>                  $payments           of the year, by due day
     * @param list<PossibleDuplicate>           $possibleDuplicates
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
        public array $payments = [],
        public array $possibleDuplicates = [],
    ) {
    }

    /**
     * The same estimate with its payments as the bank leaves them, the
     * planned items that may duplicate them, and the warnings about both.
     *
     * @param list<DuePayment>        $payments
     * @param list<PossibleDuplicate> $possibleDuplicates
     * @param list<string>            $warnings
     */
    public function withPayments(array $payments, array $possibleDuplicates, array $warnings): self
    {
        return new self($this->year, $this->today, $this->toDate, $this->toDateReason, $this->yearEnd, $this->yearEndReason, $this->amounts, $warnings, $this->unverified, $this->knownGaps, $this->forecast, $this->accruals, $payments, $possibleDuplicates);
    }
}
