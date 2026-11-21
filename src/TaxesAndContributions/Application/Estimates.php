<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\Regime;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\StatusKind;

/**
 * The estimate of a year for the owner (Goals 1-3): the regime of the owner's
 * legal form computes "to date" from the actual amounts and the months up to
 * today, as the rules and statuses of each month make them. "Year end" waits
 * for the forecast. Nothing is stored; only the owner reads it (B7).
 */
final readonly class Estimates
{
    public const string DEFAULT_LEGAL_FORM = 'sole_trader';

    /**
     * @param iterable<Regime> $regimes
     */
    public function __construct(
        private ProfileReads $profiles,
        private Amounts $amounts,
        private iterable $regimes,
        private InstanceClock $clock,
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
        $yearEnd = $today->setDate($year, 12, 31);
        $legalForm = $profile->valueOn(StatusKind::LegalForm, min($today, $yearEnd))->value ?? self::DEFAULT_LEGAL_FORM;
        $regime = $this->regime($legalForm);

        if ($year > $thisYear) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_YEAR_NOT_STARTED, null, EstimateView::YEAR_END_FORECAST_NOT_BUILT, null, [], [], []);
        }
        $amounts = $this->amounts->year($year);
        $warnings = [] === $amounts->unavailableSources ? [] : [EstimateView::WARNING_SOURCES_UNAVAILABLE];
        try {
            $toDate = $regime->compute(new RegimeInput($year, $profile, $amounts, $today, min($today, $yearEnd)));
        } catch (RulesNotAvailable) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_RULES_NOT_AVAILABLE, null, EstimateView::YEAR_END_FORECAST_NOT_BUILT, $amounts, $warnings, $amounts->unverified, $amounts->knownGaps);
        }

        return new EstimateView(
            $year,
            $today,
            $toDate,
            null,
            null,
            EstimateView::YEAR_END_FORECAST_NOT_BUILT,
            $amounts,
            $this->distinct([...$warnings, ...$toDate->warnings]),
            $this->distinct([...$amounts->unverified, ...$toDate->unverified]),
            $this->distinct([...$amounts->knownGaps, ...$toDate->knownGaps]),
        );
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
