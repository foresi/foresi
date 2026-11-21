<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ChildBenefitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\CreditsStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\FlatRateCoveredStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\FlatRateStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\HealthStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\IncomeTaxStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\PipelineStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ProfitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SicknessStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SocialStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\TaxBaseStep;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\FlatRateResult;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\MonthStatus;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\Regime;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\RulesStatus;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\Step;
use Foresi\TaxesAndContributions\Contract\SwitchAdjustment;
use Foresi\TaxesAndContributions\Contract\Totals;
use Foresi\TaxesAndContributions\Contract\YearBefore;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The Czech sole trader (OSVČ) on a cash basis: one pipeline per expense
 * method compared (old T5, T9), over the months from January to the cut-off,
 * each classified by the statuses on its first day, with the payments of the
 * year as they fall due (CzechPayments). It is an estimate, not a
 * basis for the tax return; what an accountant should still confirm is listed.
 *
 * A month whose flat_rate_band on its first day is 1, 2 or 3 is in the
 * flat-rate tax (paušální daň): it pays its band's amount, and the ordinary
 * pipeline counts the other months only. A year with only flat-rate months
 * has no ordinary levies, credits or child benefit. A year with both takes
 * the profit for the share of its ordinary active months, as a mixed main and
 * secondary year does, and says it is unverified. Becoming a VAT payer ends
 * the flat rate at the end of that tax year (ZDP § 2a (8) a) 2)): the year
 * warns, and the years after it count as ordinary while the band stays, until
 * a new band status starts. The estimate never changes a status itself.
 *
 * A year whose return leaves the actual costs of the year before for a lump
 * sum, or the ordinary tax for a whole year in the flat rate, adjusts the
 * year before (ZDP § 23 (8), CzechSwitchAdjustment): a warning with the
 * amount and a payment of its own, never inside the totals.
 */
final readonly class CzechSoleTrader implements Regime
{
    public const string KEY = 'cz.sole_trader';

    public const string WARNING_ACTIVITY_ASSUMED_MAIN = 'activity_assumed_main';
    public const string WARNING_FLAT_RATE_ENDS = 'flat_rate_ends_vat_payer';
    public const string WARNING_FLAT_RATE_ENDED = 'flat_rate_ended_vat_payer';
    public const string UNVERIFIED_INSURANCE_PROFIT = 'insurance_profit_definition';
    public const string UNVERIFIED_OFFICIAL_2027_TABLES = 'official_2027_tables';
    public const string UNVERIFIED_RULES_NOT_PUBLISHED = 'rules_not_published';
    public const string UNVERIFIED_ACT_180_2026 = 'act_180_2026';

    /** What the estimate leaves out (old T12). */
    public const array KNOWN_GAPS = ['assets_and_depreciation', 'loss_carry_forward', 'starter_relief'];

    /** The surcharge of the flat-rate band 1 for not recording sales, from 2027 (Act 180/2026). */
    public const string GAP_FLAT_RATE_SURCHARGE = 'flat_rate_surcharge';

    public function __construct(private CzechRuleBook $rules = new CzechRuleBook())
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function country(): string
    {
        return 'CZ';
    }

    public function legalForm(): string
    {
        return 'sole_trader';
    }

    public function compute(RegimeInput $input): RegimeResult
    {
        $ruleYear = $this->rules->ruleYear($input->year);
        $status = $this->rules->statusOf($input->year);
        $inputs = $input->profile->years[$input->year] ?? new YearInputsView($input->year);
        $warnings = [];
        $months = $this->months($input, $warnings);

        $methods = [];
        $used = [];
        $unverified = [self::UNVERIFIED_INSURANCE_PROFIT];
        $credits = [];
        $flatRate = null;
        foreach ($inputs->expenseMethods as $method) {
            $calculation = new Calculation($input, $inputs, $method, $months, $this->rules);
            $steps = array_map(static fn (PipelineStep $step): Step => $step->apply($calculation), $this->pipeline($calculation));
            $methods[] = new MethodResult($method, $this->totals($calculation), $steps);
            $used += $calculation->used();
            $unverified = [...$unverified, ...$calculation->unverified];
            $warnings = [...$warnings, ...$calculation->warnings];
            $credits = [] === $credits ? $calculation->credits : $credits;
            // The same for every method.
            $flatRate = [] === $calculation->flatRateMonths ? null : new FlatRateResult($calculation->flatRateMonths);
        }
        $knownGaps = self::KNOWN_GAPS;
        if ($flatRate instanceof FlatRateResult && $input->year >= Rules2027::YEAR) {
            $knownGaps[] = self::GAP_FLAT_RATE_SURCHARGE;
        }
        $statuses = $this->monthStatuses($months, $used);
        $payments = $this->payments($input, $inputs, $methods, $used, $unverified);
        $switch = $this->switchAdjustment($input, $inputs, $unverified);
        if ($switch instanceof SwitchAdjustment) {
            $warnings[] = CzechSwitchAdjustment::WARNING;
            $knownGaps[] = CzechSwitchAdjustment::GAP_INVENTORY_AND_RESERVES;
            if ($switch->tax->minor() > 0) {
                $payments[] = new DuePayment(Authority::IncomeTax, DuePayment::KIND_SWITCH_ADJUSTMENT, (string) $input->year, new \DateTimeImmutable(\sprintf('%04d-01-01', $input->year), $input->today->getTimezone()), $switch->dueOn, $switch->tax);
                usort($payments, static fn (DuePayment $a, DuePayment $b): int => [$a->dueOn->format('Y-m-d'), $a->authority->value] <=> [$b->dueOn->format('Y-m-d'), $b->authority->value]);
            }
        }
        if (RulesStatus::Derived === $status) {
            $unverified[] = self::UNVERIFIED_OFFICIAL_2027_TABLES;
        } elseif (RulesStatus::LatestUsed === $status) {
            $unverified[] = self::UNVERIFIED_RULES_NOT_PUBLISHED;
        }
        if ($input->year >= Rules2027::YEAR) {
            $unverified[] = self::UNVERIFIED_ACT_180_2026;
        }
        uasort($used, static fn (RuleValue $a, RuleValue $b): int => [$a->key, $a->validFrom] <=> [$b->key, $b->validFrom]);

        return new RegimeResult(
            self::KEY,
            $ruleYear,
            $status,
            array_values(array_map(static fn (RuleValue $value): \Foresi\TaxesAndContributions\Contract\RuleRef => $value->ref(), $used)),
            $methods,
            $this->cheaper($methods),
            $this->breakEven($methods),
            $credits,
            $statuses,
            array_values(array_unique($warnings)),
            array_values(array_unique($unverified)),
            $knownGaps,
            $payments,
            $flatRate,
            $switch,
        );
    }

    /**
     * The adjustment of the year before when this year changes the expense
     * method (ZDP § 23 (8)): from the actual costs to a lump sum, or from an
     * ordinary year to a year wholly in the flat-rate tax. The methods are
     * those the returns are filed with; while one is open, nothing is
     * assumed. A year before in the flat rate changes nothing.
     *
     * @param list<string> $unverified
     */
    private function switchAdjustment(RegimeInput $input, YearInputsView $inputs, array &$unverified): ?SwitchAdjustment
    {
        $before = $input->yearBefore;
        if (!$before instanceof YearBefore || $input->year - 1 !== $before->year) {
            return null;
        }
        $previousInputs = $input->profile->years[$before->year] ?? new YearInputsView($before->year);
        $from = $previousInputs->filedMethod();
        $ignored = [];
        $zone = $input->today->getTimezone();
        $active = array_filter($this->months($input, $ignored, $this->lastDayOf($input->year, $input->today)), static fn (Month $month): bool => $month->isActive());
        $wholeFlatRate = [] !== $active && [] === array_filter($active, static fn (Month $month): bool => !$month->isFlatRate());
        $to = $inputs->filedMethod();
        $into = match (true) {
            $wholeFlatRate => SwitchAdjustment::INTO_FLAT_RATE,
            ExpenseMethod::Actual === $from && $to instanceof ExpenseMethod && ExpenseMethod::Actual !== $to => $to->value,
            default => null,
        };
        $wasFlatRate = null !== $this->flatRateBand($input->profile, new \DateTimeImmutable(\sprintf('%04d-12-01', $before->year), $zone), $ignored);
        if (!$from instanceof ExpenseMethod || null === $into || $wasFlatRate) {
            return null;
        }
        $unverified[] = CzechSwitchAdjustment::UNVERIFIED;
        $unverified[] = CzechSwitchAdjustment::UNVERIFIED_INSURANCE;

        return new CzechSwitchAdjustment($this->rules)->of($input->year, $before, $from, $into, $this->taxBaseOf($input, $before, $previousInputs), $inputs->returnRoute, $zone, $unverified);
    }

    /**
     * The tax base of the year before with the method its return is filed
     * with, or null when that year has no rules.
     */
    private function taxBaseOf(RegimeInput $input, YearBefore $before, YearInputsView $previousInputs): ?int
    {
        try {
            $previous = $this->compute(new RegimeInput($before->year, $input->profile, $before->amounts, $input->today, $this->lastDayOf($before->year, $input->today)));
        } catch (RulesNotAvailable) {
            return null;
        }

        return Czk::minorOf(CzechPayments::paidMethod($previous->methods, $previousInputs->filedMethod(), $previous->cheaperMethod)->step(Step::TAX_BASE)->result);
    }

    /**
     * The advances of every month of the year, whatever the cut-off, and the
     * settlement in the next year once the cut-off is 31 December, by due day.
     *
     * @param list<MethodResult>       $methods
     * @param array<string, RuleValue> $used
     * @param list<string>             $unverified
     *
     * @return list<DuePayment>
     */
    private function payments(RegimeInput $input, YearInputsView $inputs, array $methods, array &$used, array &$unverified): array
    {
        $ignored = [];
        $lastDay = $this->lastDayOf($input->year, $input->today);
        $months = $this->months($input, $ignored, $lastDay);
        $plan = new CzechPayments($this->rules);
        $payments = $plan->advances($input, $months, $this->monthStatuses($months, $used), $used, $unverified);
        if ($input->cutOff->format('Y-m-d') >= $lastDay->format('Y-m-d')) {
            $payments = [...$payments, ...$plan->settlement($input, CzechPayments::paidMethod($methods, $inputs->chosenMethod, $this->cheaper($methods)), $payments, $unverified)];
        }
        usort($payments, static fn (DuePayment $a, DuePayment $b): int => [$a->dueOn->format('Y-m-d'), $a->authority->value] <=> [$b->dueOn->format('Y-m-d'), $b->authority->value]);

        return $payments;
    }

    /**
     * 31 December of the year, in the zone of today.
     */
    private function lastDayOf(int $year, \DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setDate($year, 12, 31);
    }

    /**
     * The ordinary steps, and the flat rate after them in a year with
     * flat-rate months. When every active month is in it, the ordinary levies
     * are covered by it; sickness insurance is still paid apart.
     *
     * @return list<PipelineStep>
     */
    private function pipeline(Calculation $calculation): array
    {
        $ordinary = [new ProfitStep(), new SocialStep(), new HealthStep(), new SicknessStep(), new TaxBaseStep(), new IncomeTaxStep(), new CreditsStep(), new ChildBenefitStep()];
        if ([] === $calculation->flatRateMonths()) {
            return $ordinary;
        }
        if (!$calculation->hasOrdinaryMonths()) {
            $covered = static fn (string $key): PipelineStep => new FlatRateCoveredStep($key);

            return [new ProfitStep(), $covered(Step::SOCIAL), $covered(Step::HEALTH), new SicknessStep(), $covered(Step::TAX_BASE), $covered(Step::INCOME_TAX), $covered(Step::CREDITS), $covered(Step::CHILD_BENEFIT), new FlatRateStep()];
        }
        $calculation->flag(Calculation::UNVERIFIED_FLAT_RATE_MIXED_YEAR);

        return [...$ordinary, new FlatRateStep()];
    }

    /**
     * January to the month of the cut-off, or of the one given, each by the statuses on its first
     * day. Without any activity entered, every month is a main one, and the
     * result says so.
     *
     * @param list<string> $warnings
     *
     * @return list<Month>
     */
    private function months(RegimeInput $input, array &$warnings, ?\DateTimeImmutable $cutOff = null): array
    {
        $profile = $input->profile;
        $anyActivity = [] !== array_filter($profile->statuses, static fn (StatusPeriod $status): bool => StatusKind::Activity === $status->kind);
        if (!$anyActivity) {
            $warnings[] = self::WARNING_ACTIVITY_ASSUMED_MAIN;
        }
        $zone = $input->today->getTimezone();
        $cutOff = ($cutOff ?? $input->cutOff)->format('Y-m-d');
        $months = [];
        for ($number = 1; $number <= 12; ++$number) {
            $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $input->year, $number), $zone);
            if ($first->format('Y-m-d') > $cutOff) {
                break;
            }
            $sickness = $profile->valueOn(StatusKind::SicknessInsurance, $first);
            $activity = $anyActivity ? ($profile->valueOn(StatusKind::Activity, $first)->value ?? Month::NONE) : Month::MAIN;
            $months[] = new Month(
                $first,
                $activity,
                'yes' === $profile->valueOn(StatusKind::HealthMinimumExempt, $first)?->value,
                'on' === $sickness?->value,
                $sickness?->base?->minor(),
                Month::NONE === $activity ? null : $this->flatRateBand($profile, $first, $warnings),
            );
        }

        return $months;
    }

    /**
     * The band of a month in the flat-rate tax, or null: the band on its
     * first day, unless the owner became a VAT payer while in it in a year
     * before, or is one when the year starts. Becoming one this year ends it
     * at the year end, with a warning.
     *
     * @param list<string> $warnings
     */
    private function flatRateBand(ProfileView $profile, \DateTimeImmutable $first, array &$warnings): ?string
    {
        $band = $profile->valueOn(StatusKind::FlatRateBand, $first);
        if (!$band instanceof StatusPeriod || !\in_array($band->value, CzechFlatRate::BANDS, true)) {
            return null;
        }
        $year = $first->format('Y');
        $ended = 'yes' === $profile->valueOn(StatusKind::VatPayer, $first->setDate((int) $year, 1, 1))?->value;
        $ends = false;
        foreach ($profile->statuses as $status) {
            if (StatusKind::VatPayer !== $status->kind || 'yes' !== $status->value || $status->validFrom->format('Y-m-d') < $band->validFrom->format('Y-m-d')) {
                continue;
            }
            $ended = $ended || $status->validFrom->format('Y') < $year;
            $ends = $ends || $status->validFrom->format('Y') === $year;
        }
        if ($ended) {
            $warnings[] = self::WARNING_FLAT_RATE_ENDED;

            return null;
        }
        if ($ends) {
            $warnings[] = self::WARNING_FLAT_RATE_ENDS;
        }

        return $band->value;
    }

    /**
     * The smallest advances of each month by the rules of that month, as they
     * are paid during the year: in 2026 the social one follows 40 % of the
     * average wage to June and 35 % from July.
     *
     * @param list<Month>              $months
     * @param array<string, RuleValue> $used
     *
     * @return list<MonthStatus>
     */
    private function monthStatuses(array $months, array &$used): array
    {
        $rule = function (string $key, \DateTimeImmutable $day) use (&$used): RuleValue {
            $value = $this->rules->valueOn($key, $day);
            $used[$value->key.'@'.$value->validFrom->format('Y-m-d')] = $value;

            return $value;
        };
        $statuses = [];
        foreach ($months as $month) {
            if ($month->isFlatRate()) {
                [$flat] = CzechFlatRate::month($month, $rule);
                $statuses[] = new MonthStatus($month->key(), $month->activity, $month->healthMinimumExempt, $month->sicknessInsurance, $flat->social, $flat->health, $month->flatRateBand);

                continue;
            }
            $wage = $rule('average_wage', $month->first)->minor();
            $social = 0;
            if ($month->isActive()) {
                $share = $rule(Month::SECONDARY === $month->activity ? 'social.minimum_share.secondary' : 'social.minimum_share.main', $month->first)->value;
                $social = Czk::percentUp(Czk::percentUp($wage, $share), $rule('social.rate', $month->first)->value);
            }
            $health = 0;
            if ($month->isActive() && !$month->healthMinimumExempt) {
                $health = Czk::percentUp(Czk::percent($wage, $rule('health.minimum_share', $month->first)->value), $rule('health.rate', $month->first)->value);
            }
            $statuses[] = new MonthStatus($month->key(), $month->activity, $month->healthMinimumExempt, $month->sicknessInsurance, Czk::money($social), Czk::money($health));
        }

        return $statuses;
    }

    private function totals(Calculation $calculation): Totals
    {
        $levies = $calculation->social + $calculation->health + $calculation->sickness + $calculation->incomeTax + $calculation->flatRate;

        return new Totals(
            Czk::money($calculation->income),
            Czk::money($calculation->costs),
            Czk::money($calculation->expenses),
            Czk::money($calculation->profit),
            Czk::money($calculation->social),
            Czk::money($calculation->health),
            Czk::money($calculation->sickness),
            Czk::money($calculation->incomeTax),
            Czk::money($calculation->childBonus),
            Czk::money($levies),
            Czk::money($calculation->income - $calculation->costs - $levies + $calculation->childBonus),
            Czk::money($calculation->socialBase),
            Czk::money($calculation->flatRate),
        );
    }

    /**
     * The method that leaves the most, when there are two or more; the first
     * of equals.
     *
     * @param list<MethodResult> $methods
     */
    private function cheaper(array $methods): ?ExpenseMethod
    {
        if (\count($methods) < 2) {
            return null;
        }
        $best = $methods[0];
        foreach ($methods as $result) {
            if ($result->totals->takeHome->minor() > $best->totals->takeHome->minor()) {
                $best = $result;
            }
        }

        return $best->method;
    }

    /**
     * How much more real cost would make the actual costs cost as much as the
     * cheapest lump sum: the same claimed expenses give the same profit, so
     * the same levies. Below zero, the actual costs already cost less.
     *
     * @param list<MethodResult> $methods
     */
    private function breakEven(array $methods): ?\Foresi\Shared\Domain\Money
    {
        $actual = null;
        $lump = null;
        foreach ($methods as $result) {
            if (ExpenseMethod::Actual === $result->method) {
                $actual = $result;
            } elseif (null === $lump || $result->totals->takeHome->minor() > $lump->totals->takeHome->minor()) {
                $lump = $result;
            }
        }
        if (null === $actual || null === $lump) {
            return null;
        }

        return $lump->totals->expenses->minus($actual->totals->expenses);
    }
}
