<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ChildBenefitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\CreditsStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\HealthStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\IncomeTaxStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\PipelineStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ProfitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SicknessStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SocialStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\TaxBaseStep;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\MonthStatus;
use Foresi\TaxesAndContributions\Contract\Regime;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RulesStatus;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\Totals;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The Czech sole trader (OSVČ) on a cash basis: one pipeline per expense
 * method compared (old T5, T9), over the months from January to the cut-off,
 * each classified by the statuses on its first day. It is an estimate, not a
 * basis for the tax return; what an accountant should still confirm is listed.
 */
final readonly class CzechSoleTrader implements Regime
{
    public const string KEY = 'cz.sole_trader';

    public const string WARNING_ACTIVITY_ASSUMED_MAIN = 'activity_assumed_main';
    public const string WARNING_FLAT_RATE_NOT_COMPUTED = 'flat_rate_not_computed';
    public const string UNVERIFIED_INSURANCE_PROFIT = 'insurance_profit_definition';
    public const string UNVERIFIED_OFFICIAL_2027_TABLES = 'official_2027_tables';
    public const string UNVERIFIED_RULES_NOT_PUBLISHED = 'rules_not_published';
    public const string UNVERIFIED_ACT_180_2026 = 'act_180_2026';

    /** What the estimate leaves out (old T12). */
    public const array KNOWN_GAPS = ['assets_and_depreciation', 'loss_carry_forward', 'starter_relief'];

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
        foreach ($inputs->expenseMethods as $method) {
            $calculation = new Calculation($input, $inputs, $method, $months, $this->rules);
            $steps = array_map(static fn (PipelineStep $step): \Foresi\TaxesAndContributions\Contract\Step => $step->apply($calculation), $this->pipeline());
            $methods[] = new MethodResult($method, $this->totals($calculation), $steps);
            $used += $calculation->used();
            $unverified = [...$unverified, ...$calculation->unverified];
            $warnings = [...$warnings, ...$calculation->warnings];
            $credits = [] === $credits ? $calculation->credits : $credits;
        }
        $statuses = $this->monthStatuses($months, $used);
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
            self::KNOWN_GAPS,
        );
    }

    /**
     * @return list<PipelineStep>
     */
    private function pipeline(): array
    {
        return [new ProfitStep(), new SocialStep(), new HealthStep(), new SicknessStep(), new TaxBaseStep(), new IncomeTaxStep(), new CreditsStep(), new ChildBenefitStep()];
    }

    /**
     * January to the month of the cut-off, each by the statuses on its first
     * day. Without any activity entered, every month is a main one, and the
     * result says so.
     *
     * @param list<string> $warnings
     *
     * @return list<Month>
     */
    private function months(RegimeInput $input, array &$warnings): array
    {
        $profile = $input->profile;
        $anyActivity = [] !== array_filter($profile->statuses, static fn (\Foresi\TaxesAndContributions\Contract\StatusPeriod $status): bool => StatusKind::Activity === $status->kind);
        if (!$anyActivity) {
            $warnings[] = self::WARNING_ACTIVITY_ASSUMED_MAIN;
        }
        $zone = $input->today->getTimezone();
        $cutOff = $input->cutOff->format('Y-m-d');
        $months = [];
        for ($number = 1; $number <= 12; ++$number) {
            $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $input->year, $number), $zone);
            if ($first->format('Y-m-d') > $cutOff) {
                break;
            }
            $sickness = $profile->valueOn(StatusKind::SicknessInsurance, $first);
            $band = $profile->valueOn(StatusKind::FlatRateBand, $first)?->value;
            if (null !== $band && 'none' !== $band) {
                $warnings[] = self::WARNING_FLAT_RATE_NOT_COMPUTED;
            }
            $months[] = new Month(
                $first,
                $anyActivity ? ($profile->valueOn(StatusKind::Activity, $first)->value ?? Month::NONE) : Month::MAIN,
                'yes' === $profile->valueOn(StatusKind::HealthMinimumExempt, $first)?->value,
                'on' === $sickness?->value,
                $sickness?->base?->minor(),
            );
        }

        return $months;
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
        $levies = $calculation->social + $calculation->health + $calculation->sickness + $calculation->incomeTax;

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
