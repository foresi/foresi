<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\Regime;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The estimate of a year for the owner (Goals 1-3). The regime of the owner's
 * legal form computes "to date" from the actual amounts and the months up to
 * today, as the rules and statuses of each month make them. It computes
 * "year end" from the same amounts plus the Cash flow forecast from today to
 * 31 December, over every month of the year. Both are kept apart, and the
 * forecast part is split by certainty. Without Cash flow, the year end of
 * this year is null and says why. Nothing is stored; only the owner reads it
 * (B7).
 */
final readonly class Estimates
{
    public const string DEFAULT_LEGAL_FORM = 'sole_trader';

    /**
     * @param iterable<Regime>               $regimes
     * @param iterable<ForecastAmountSource> $forecasts Cash flow's, when it is installed
     */
    public function __construct(
        private ProfileReads $profiles,
        private Amounts $amounts,
        private iterable $regimes,
        private InstanceClock $clock,
        private iterable $forecasts = [],
        private YearEndForecast $yearEnd = new YearEndForecast([]),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws TaxesRefused owner_only; invalid_year for a year before 2000 or
     *                      after the next one; no_regime when no country
     *                      module computes the owner's legal form
     */
    public function of(int $year): EstimateView
    {
        $profile = $this->profiles->profile();
        $today = $this->clock->today();
        $thisYear = (int) $today->format('Y');
        if ($year < ProfileWrites::FIRST_YEAR || $year > $thisYear + 1) {
            throw TaxesRefused::invalid('invalid_year', 'year', \sprintf('The year is one from %d to next year.', ProfileWrites::FIRST_YEAR));
        }
        $lastDay = $today->setDate($year, 12, 31);
        $legalForm = $profile->valueOn(StatusKind::LegalForm, min($today, $lastDay))->value ?? self::DEFAULT_LEGAL_FORM;
        $regime = $this->regime($legalForm);

        if ($year > $thisYear) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_YEAR_NOT_STARTED, null, EstimateView::TO_DATE_YEAR_NOT_STARTED, null, [], [], []);
        }
        $amounts = $this->amounts->year($year);
        $warnings = [] === $amounts->unavailableSources ? [] : [EstimateView::WARNING_SOURCES_UNAVAILABLE];
        try {
            $toDate = $regime->compute(new RegimeInput($year, $profile, $amounts, $today, min($today, $lastDay)));
        } catch (RulesNotAvailable) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_RULES_NOT_AVAILABLE, null, EstimateView::TO_DATE_RULES_NOT_AVAILABLE, $amounts, $warnings, $amounts->unverified, $amounts->knownGaps);
        }
        $unverified = [...$amounts->unverified, ...$toDate->unverified];
        $knownGaps = [...$amounts->knownGaps, ...$toDate->knownGaps];
        $warnings = [...$warnings, ...$toDate->warnings];

        // A past year is over: its year end is what was paid, every month counted.
        $yearEnd = $year < $thisYear ? $toDate : null;
        $yearEndReason = null;
        $forecast = null;
        $combined = $amounts;
        if ($year === $thisYear) {
            $source = $this->forecastSource();
            if (!$source instanceof ForecastAmountSource) {
                $yearEndReason = EstimateView::YEAR_END_CASH_FLOW_NOT_INSTALLED;
            } else {
                try {
                    $forecast = $this->yearEnd->of($source, $profile, $today, $lastDay);
                } catch (\DomainException $refused) {
                    $this->logger->warning('The forecast for the year end could not be read.', ['exception' => $refused]);
                    $yearEndReason = EstimateView::YEAR_END_FORECAST_UNAVAILABLE;
                }
            }
            if ($forecast instanceof ForecastView) {
                $combined = YearEndForecast::combined($amounts, $forecast);
                $yearEnd = $regime->compute(new RegimeInput($year, $profile, $combined, $today, $lastDay));
                // The months to come may bring statuses the months so far do not have.
                $warnings = [...$warnings, ...$yearEnd->warnings, ...($forecast->incomplete ? [EstimateView::WARNING_FORECAST_INCOMPLETE] : [])];
                $unverified = [...$unverified, ...$forecast->unverified, ...$yearEnd->unverified];
            }
        }

        return new EstimateView(
            $year,
            $today,
            $toDate,
            null,
            $yearEnd,
            $yearEndReason,
            $amounts,
            $this->distinct($warnings),
            $this->distinct($unverified),
            $this->distinct($knownGaps),
            $forecast,
            $yearEnd instanceof RegimeResult ? Accruals::of($yearEnd, $combined) : [],
        );
    }

    /**
     * The forecast of Cash flow; null when it is not installed.
     */
    private function forecastSource(): ?ForecastAmountSource
    {
        foreach ($this->forecasts as $source) {
            return $source;
        }

        return null;
    }

    /**
     * @throws TaxesRefused no_regime
     */
    private function regime(string $legalForm): Regime
    {
        foreach ($this->regimes as $regime) {
            if ($legalForm === $regime->legalForm()) {
                return $regime;
            }
        }

        throw TaxesRefused::conflict('no_regime', \sprintf('No installed country module computes the legal form %s.', $legalForm));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function distinct(array $values): array
    {
        return array_values(array_unique($values));
    }
}
