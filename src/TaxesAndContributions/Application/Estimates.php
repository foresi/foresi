<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\PlannedLevySource;
use Foresi\TaxesAndContributions\Contract\PossibleDuplicate;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\Regime;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\YearBefore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The estimate of a year for the owner (Goals 1-3). The regime of the owner's
 * legal form computes "to date" from the actual amounts and the months up to
 * today, as the rules and statuses of each month make them. It computes
 * "year end" from the same amounts plus the Cash flow forecast from today to
 * 31 December, over every month of the year. Both are kept apart, and the
 * forecast part is split by certainty. Without Cash flow, the year end of
 * this year is null and says why. The payments of the year come with it, each
 * matched to the bank, and the same payments not yet paid feed the estimate
 * flows of the cash flow. When the year before has its expense method
 * filed, the regime gets that year too, its amounts and what was open at its
 * end, so it can tell a change of method and add its adjustment. Nothing is
 * stored; only the owner reads the estimate (B7).
 */
final readonly class Estimates
{
    public const string DEFAULT_LEGAL_FORM = 'sole_trader';

    /**
     * @param iterable<Regime>               $regimes
     * @param iterable<ForecastAmountSource> $forecasts     Cash flow's, when it is installed
     * @param iterable<PlannedLevySource>    $plannedLevies Cash flow's, when it is installed
     */
    public function __construct(
        private ProfileReads $profiles,
        private Amounts $amounts,
        private iterable $regimes,
        private InstanceClock $clock,
        private iterable $forecasts = [],
        private YearEndForecast $yearEnd = new YearEndForecast([]),
        private LoggerInterface $logger = new NullLogger(),
        private DuePayments $payments = new DuePayments(),
        private iterable $plannedLevies = [],
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
        $view = $this->view($year, $profile);
        $around = [...$this->neighbour($year - 1, $profile), ...$view->payments, ...$this->neighbour($year + 1, $profile)];
        [$stated, $unavailable] = $this->payments->stated($around, $profile, $this->clock->today());
        $payments = array_values(array_filter($stated, static fn (DuePayment $payment): bool => $payment->taxYear() === $year));
        $duplicates = $this->duplicates();
        $warnings = $view->warnings;
        if ([] !== $duplicates) {
            $warnings[] = EstimateView::WARNING_POSSIBLE_DUPLICATES;
        }
        if ([] !== array_filter($payments, static fn (DuePayment $payment): bool => !$payment->hasMatchRule())) {
            $warnings[] = EstimateView::WARNING_NO_MATCH_RULE;
        }
        if ($unavailable) {
            $warnings[] = EstimateView::WARNING_PAYMENTS_UNAVAILABLE;
        }

        return $view->withPayments($payments, $duplicates, $this->distinct($warnings));
    }

    /**
     * The payments not yet paid that the estimate flows put into the cash
     * flow: of last year, this year and next year, due by $to, an overdue one
     * only when the bank could have paid it, so its match rule is set. Before
     * the owner enters an activity there are none. It asks nobody who reads:
     * Cash flow shows these flows to anyone but the owner as category totals
     * only (B7).
     *
     * @return list<DuePayment>
     */
    public function openPayments(\DateTimeImmutable $to): array
    {
        $profile = $this->profiles->forEstimateFlows();
        if ([] === array_filter($profile->statuses, static fn (StatusPeriod $status): bool => StatusKind::Activity === $status->kind)) {
            return [];
        }
        $today = $this->clock->today();
        $thisYear = (int) $today->format('Y');
        $expected = $this->neighbour($thisYear - 1, $profile);
        // This year's settlement falls in the next one, so this year is read with its year end.
        $expected = [...$expected, ...$this->view($thisYear, $profile)->payments];
        $expected = [...$expected, ...$this->neighbour($thisYear + 1, $profile)];
        [$stated] = $this->payments->stated($expected, $profile, $today);
        $last = $to->format('Y-m-d');

        return array_values(array_filter($stated, static fn (DuePayment $payment): bool => $payment->dueOn->format('Y-m-d') <= $last
            && (DuePayment::STATE_EXPECTED === $payment->state || (DuePayment::STATE_OVERDUE === $payment->state && $payment->hasMatchRule()))));
    }

    /**
     * The estimate of a year with its payments expected, before the bank
     * says which are paid.
     *
     * @throws TaxesRefused invalid_year; no_regime
     */
    private function view(int $year, ProfileView $profile): EstimateView
    {
        $today = $this->clock->today();
        $thisYear = (int) $today->format('Y');
        if ($year < ProfileWrites::FIRST_YEAR || $year > $thisYear + 1) {
            throw TaxesRefused::invalid('invalid_year', 'year', \sprintf('The year is one from %d to next year.', ProfileWrites::FIRST_YEAR));
        }
        $lastDay = $today->setDate($year, 12, 31);
        $regime = $this->regimeOf($year, $profile);
        if ($year > $thisYear) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_YEAR_NOT_STARTED, null, EstimateView::TO_DATE_YEAR_NOT_STARTED, null, [], [], [], payments: $this->advancesOf($regime, $year, $profile, $today));
        }
        $amounts = $this->amounts->yearOf($year, $profile);
        $warnings = [] === $amounts->unavailableSources ? [] : [EstimateView::WARNING_SOURCES_UNAVAILABLE];
        $before = $this->yearBefore($year, $profile);
        if ($before instanceof YearBefore && [] !== $before->open->unavailableSources) {
            $warnings[] = EstimateView::WARNING_SOURCES_UNAVAILABLE;
        }
        try {
            $toDate = $regime->compute(new RegimeInput($year, $profile, $amounts, $today, min($today, $lastDay), $before));
        } catch (RulesNotAvailable) {
            return new EstimateView($year, $today, null, EstimateView::TO_DATE_RULES_NOT_AVAILABLE, null, EstimateView::TO_DATE_RULES_NOT_AVAILABLE, $amounts, $warnings, $amounts->unverified, $amounts->knownGaps);
        }
        $unverified = [...$amounts->unverified, ...($before instanceof YearBefore ? $before->open->unverified : []), ...$toDate->unverified];
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
                $yearEnd = $regime->compute(new RegimeInput($year, $profile, $combined, $today, $lastDay, $before));
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
            // The year end adds the settlement; without it, "to date" has every advance of the year.
            ($yearEnd ?? $toDate)->payments,
        );
    }

    /**
     * The year before, when its return is filed with a known expense method:
     * what was paid in it and what was open at its last day. Without it no
     * change of method can be told, so nothing more is read.
     */
    private function yearBefore(int $year, ProfileView $profile): ?YearBefore
    {
        $previous = $year - 1;
        if ($previous < ProfileWrites::FIRST_YEAR || !($profile->years[$previous] ?? null)?->filedMethod() instanceof ExpenseMethod) {
            return null;
        }

        return new YearBefore($previous, $this->amounts->yearOf($previous, $profile), $this->amounts->openAt($this->clock->today()->setDate($previous, 12, 31), $profile));
    }

    /**
     * The payments expected in a year beside the one asked, so a bank
     * movement pays one payment only. A past year has its settlement from
     * what was paid; this year and the next have only their advances here,
     * as the bank has nothing yet that could pay a later settlement, so the
     * forecast is not read for them.
     *
     * @return list<DuePayment>
     */
    private function neighbour(int $year, ProfileView $profile): array
    {
        $today = $this->clock->today();
        $thisYear = (int) $today->format('Y');
        if ($year < ProfileWrites::FIRST_YEAR || $year > $thisYear + 1) {
            return [];
        }
        if ($year < $thisYear) {
            return $this->view($year, $profile)->payments;
        }

        return $this->advancesOf($this->regimeOf($year, $profile), $year, $profile, $today);
    }

    /**
     * The advances of a year: they follow the year before and the minimums,
     * not its amounts, which nobody knows yet for a year to come.
     *
     * @return list<DuePayment>
     */
    private function advancesOf(Regime $regime, int $year, ProfileView $profile, \DateTimeImmutable $today): array
    {
        // Cut off today, before the year ends: no settlement, and no month counted from amounts nobody knows.
        try {
            return $regime->compute(new RegimeInput($year, $profile, AmountsView::none($today, $today), $today, $today))->payments;
        } catch (RulesNotAvailable) {
            return [];
        }
    }

    /**
     * The planned items of Cash flow that may count the levies twice (B5);
     * none without Cash flow, or when it cannot say.
     *
     * @return list<PossibleDuplicate>
     */
    private function duplicates(): array
    {
        foreach ($this->plannedLevies as $source) {
            try {
                return $source->activeLevyItems();
            } catch (\Throwable $failure) {
                $this->logger->warning('The planned items of the levies could not be read.', ['exception' => $failure]);

                return [];
            }
        }

        return [];
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
     * The regime of the owner's legal form in the year, as on today or on
     * the year's last day when it is over.
     *
     * @throws TaxesRefused no_regime
     */
    private function regimeOf(int $year, ProfileView $profile): Regime
    {
        $today = $this->clock->today();

        return $this->regime($profile->valueOn(StatusKind::LegalForm, min($today, $today->setDate($year, 12, 31)))->value ?? self::DEFAULT_LEGAL_FORM);
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
