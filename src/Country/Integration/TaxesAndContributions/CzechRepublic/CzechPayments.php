<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\MonthStatus;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * When a Czech sole trader pays what (old T7), as the rules and statuses of
 * each month make it.
 *
 * - The social advance of a month is due on its last day (SP act § 14a (1)),
 *   the voluntary sickness one too, and the health advance by the 8th of the
 *   next month (ZP act § 7 (2)).
 * - An advance is the previous year's base per month of its activity times
 *   the rate, rounded up, and at least the minimum of the month; with no base
 *   known, it is the minimum. The months of activity are those with an
 *   activity at least for part of the month, main or secondary alike (ZP act
 *   § 8 (2), SP act § 14 (2), zakonyprolidi.cz/cs/1992-592 and /1992-589 as
 *   in force on 1 November 2026): the count the owner entered for the year,
 *   else the activity statuses of the year before, else twelve, flagged. A secondary activity pays a social advance only
 *   with a base from the year before, as it took part then. The new base
 *   applies to social from the month after the overview and to health from
 *   the overview month (SP act § 14 (6), ZP act § 8 (2)).
 * - The income tax advances follow the last known tax (ZDP § 38a): none up to
 *   30,000, two of 40 % on 15 June and 15 December up to 150,000, four of a
 *   quarter on the 15th of March, June, September and December above it; each
 *   rounded up to hundreds (DŘ § 146 (2)), on the next working day.
 * - The settlement falls in the next year: the income tax on the return
 *   deadline of the route, the social and health shortfall 8 days after the
 *   overview. What the year's advances paid too much comes back.
 * - A month in the flat-rate tax pays its band's amount to the tax office by
 *   the 20th, the first month of the regime by the 20th of the next month, on
 *   the next working day (ZDP § 38lk); it has no social, health or income tax
 *   advance and no settlement. Voluntary sickness insurance stays apart.
 */
final readonly class CzechPayments
{
    public const string UNVERIFIED_MIDDLE_BAND = 'middle_advance_band';
    public const string UNVERIFIED_ELECTRONIC_DEADLINE = 'electronic_deadline_payment_date';
    public const string UNVERIFIED_OVERVIEW_DEADLINES = 'overview_deadlines';
    public const string UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS = 'previous_activity_months';

    /** The months of the four quarterly advances and of the two half-year ones. */
    private const array FOUR = [3, 6, 9, 12];
    private const array TWO = [6, 12];

    public function __construct(private CzechRuleBook $rules)
    {
    }

    /**
     * Every advance of the year, month by month, then the income tax ones.
     *
     * @param list<Month>              $months     every month of the year
     * @param list<MonthStatus>        $statuses   the same months with their minimum advances
     * @param array<string, RuleValue> $used
     * @param list<string>             $unverified
     *
     * @return list<DuePayment>
     */
    public function advances(RegimeInput $input, array $months, array $statuses, array &$used, array &$unverified): array
    {
        $zone = $input->today->getTimezone();
        $profile = $input->profile;
        $year = $input->year;
        $overview = $this->overviewOf($year - 1, $profile, $zone);
        $before = $this->bases($profile, $year - 1);
        $after = $this->bases($profile, $year);
        $payments = [];
        $flatRate = [];
        foreach ($months as $index => $month) {
            if (!$month->isActive()) {
                continue;
            }
            if ($month->sicknessInsurance) {
                $payments[] = $this->sicknessAdvance($month, $used);
            }
            if ($month->isFlatRate()) {
                $flatRate[] = $month->key();
                $payments[] = $this->flatRateAdvance($month, 0 !== $index && !$months[$index - 1]->isFlatRate(), $used, $unverified);

                continue;
            }
            $status = $statuses[$index];
            $first = $month->first;
            $last = $first->modify('last day of this month');
            $period = $month->key();
            $social = $first->format('Y-m') > $overview->format('Y-m') ? $after : $before;
            $health = $first->format('Y-m') >= $overview->format('Y-m') ? $after : $before;
            foreach ([[$social, 'social'], [$health, 'health']] as [$bases, $levy]) {
                if ($bases['guessed'] && null !== $bases[$levy] && !\in_array(self::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS, $unverified, true)) {
                    $unverified[] = self::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS;
                }
            }

            $socialRate = $this->rule('social.rate', $first, $used)->value;
            $socialAdvance = Czk::percentUp(Czk::fractionUp($social['social'] ?? 0, 1, $social['months']), $socialRate);
            if (Month::MAIN === $month->activity || $socialAdvance > 0) {
                $amount = max($socialAdvance, $status->minimumSocialAdvance->minor());
                $payments[] = new DuePayment(Authority::Social, DuePayment::KIND_ADVANCE, $period, $first, $last, Czk::money($amount));
            }

            $healthRate = $this->rule('health.rate', $first, $used)->value;
            $healthAdvance = Czk::percentUp(Czk::fractionUp($health['health'] ?? 0, 1, $health['months']), $healthRate);
            $amount = max($healthAdvance, $status->minimumHealthAdvance->minor());
            if ($amount > 0) {
                $payments[] = new DuePayment(Authority::Health, DuePayment::KIND_ADVANCE, $period, $first, $first->modify('first day of next month')->modify('+7 days'), Czk::money($amount));
            }
        }

        return [...$payments, ...$this->taxAdvances($input, $flatRate, $used, $unverified)];
    }

    /**
     * The settlement of the year with the chosen expense method: what the
     * year's levies leave after its advances, due in the next year, or what
     * comes back.
     *
     * @param list<DuePayment> $advances
     * @param list<string>     $unverified
     *
     * @return list<DuePayment>
     */
    public function settlement(RegimeInput $input, MethodResult $method, array $advances, array &$unverified): array
    {
        $zone = $input->today->getTimezone();
        $year = $input->year;
        $inputs = $this->inputs($input->profile, $year);
        $route = $inputs->returnRoute;
        $from = new \DateTimeImmutable(\sprintf('%04d-01-01', $year + 1), $zone);
        $returnDay = CzechDeadlines::returnDeadline($year, $route, $zone);
        $overview = $this->overviewOf($year, $input->profile, $zone);
        if (!$this->inputs($input->profile, $year + 1)->previousOverviewFiledOn instanceof \DateTimeImmutable) {
            $unverified[] = self::UNVERIFIED_OVERVIEW_DEADLINES;
        }
        if (ReturnRoute::Electronic === $route) {
            $unverified[] = self::UNVERIFIED_ELECTRONIC_DEADLINE;
        }
        $insuranceDay = $overview->modify(\sprintf('+%d days', CzechDeadlines::SETTLEMENT_DAYS));
        $totals = $method->totals;
        $tax = $totals->incomeTax->minor() - $totals->childBonus->minor() - ($inputs->employmentTaxWithheld?->minor() ?? 0);

        $rests = [];
        foreach ([[Authority::Social, $totals->social->minor(), $insuranceDay], [Authority::Health, $totals->health->minor(), $insuranceDay], [Authority::IncomeTax, $tax, $returnDay]] as [$authority, $levy, $due]) {
            $rest = $this->rest($authority, $levy, $advances, $year, $from, $due);
            if ($rest instanceof DuePayment) {
                $rests[] = $rest;
            }
        }

        return $rests;
    }

    /**
     * The method whose settlement is paid: the one chosen for the return, or
     * the cheaper one, or the first compared.
     *
     * @param list<MethodResult> $methods
     */
    public static function paidMethod(array $methods, ?ExpenseMethod $chosen, ?ExpenseMethod $cheaper): MethodResult
    {
        foreach ([$chosen, $cheaper] as $wanted) {
            foreach ($methods as $result) {
                if ($wanted === $result->method) {
                    return $result;
                }
            }
        }

        return $methods[0];
    }

    /**
     * @param list<DuePayment> $advances
     */
    private function rest(Authority $authority, int $levy, array $advances, int $year, \DateTimeImmutable $from, \DateTimeImmutable $due): ?DuePayment
    {
        $paid = 0;
        foreach ($advances as $advance) {
            if ($authority === $advance->authority) {
                $paid += $advance->amount->minor();
            }
        }
        $rest = $levy - $paid;
        if (0 === $rest) {
            return null;
        }

        return new DuePayment($authority, $rest > 0 ? DuePayment::KIND_SETTLEMENT : DuePayment::KIND_OVERPAYMENT, (string) $year, $from, $due, Czk::money(abs($rest)));
    }

    /**
     * @param array<string, RuleValue> $used
     */
    private function sicknessAdvance(Month $month, array &$used): DuePayment
    {
        $first = $month->first;
        $minimum = $this->rule('sickness.minimum_base', $first, $used)->minor();
        $base = max($month->sicknessBase ?? $minimum, $minimum);

        return new DuePayment(Authority::Sickness, DuePayment::KIND_ADVANCE, $month->key(), $first, $first->modify('last day of this month'), Czk::money(Czk::percentUp($base, $this->rule('sickness.rate', $first, $used)->value)));
    }

    /**
     * @param array<string, RuleValue> $used
     * @param list<string>             $unverified
     */
    private function flatRateAdvance(Month $month, bool $firstMonth, array &$used, array &$unverified): DuePayment
    {
        [$flat, $notPublished] = CzechFlatRate::month($month, fn (string $key, \DateTimeImmutable $day): RuleValue => $this->rule($key, $day, $used));
        if ($notPublished) {
            $unverified[] = CzechFlatRate::UNVERIFIED_2027_BAND_1;
        }

        return new DuePayment(Authority::FlatRate, DuePayment::KIND_ADVANCE, $month->key(), $month->first, CzechFlatRate::dueOn($month->first, $firstMonth), $flat->total());
    }

    /**
     * The income tax advances, but none in a month of the flat-rate tax.
     *
     * @param list<string>             $flatRate   the keys of the flat-rate months
     * @param array<string, RuleValue> $used
     * @param list<string>             $unverified
     *
     * @return list<DuePayment>
     */
    private function taxAdvances(RegimeInput $input, array $flatRate, array &$used, array &$unverified): array
    {
        $year = $input->year;
        $zone = $input->today->getTimezone();
        $first = new \DateTimeImmutable(\sprintf('%04d-01-01', $year), $zone);
        $known = $this->inputs($input->profile, $year)->lastKnownTax;
        // A flat-rate taxpayer pays the monthly flat advances instead (ZDP § 38a).
        if (!$known instanceof Money || \count($flatRate) >= 12 || $known->minor() <= $this->rule('tax.advance.none_up_to', $first, $used)->minor()) {
            return [];
        }
        $last = $known->minor();
        if ($last <= $this->rule('tax.advance.two_up_to', $first, $used)->minor()) {
            $unverified[] = self::UNVERIFIED_MIDDLE_BAND;
            [$months, $share] = [self::TWO, $this->rule('tax.advance.two_share', $first, $used)->value];
        } else {
            [$months, $share] = [self::FOUR, $this->rule('tax.advance.four_share', $first, $used)->value];
        }
        $amount = Czk::money(Czk::upToHundreds(Czk::percent($last, $share)));
        $payments = [];
        $from = $first;
        foreach ($months as $month) {
            if (\in_array(\sprintf('%04d-%02d', $year, $month), $flatRate, true)) {
                continue;
            }
            $due = CzechDeadlines::workingDayFrom(new \DateTimeImmutable(\sprintf('%04d-%02d-15', $year, $month), $zone));
            $payments[] = new DuePayment(Authority::IncomeTax, DuePayment::KIND_ADVANCE, $due->format('Y-m'), $from, $due, $amount);
            $from = $due->modify('+1 day');
        }

        return $payments;
    }

    /**
     * The day the overviews of a tax year were filed, as the next year's
     * inputs say, or else their deadline by the year's return route.
     */
    private function overviewOf(int $taxYear, ProfileView $profile, \DateTimeZone $zone): \DateTimeImmutable
    {
        $filed = $this->inputs($profile, $taxYear + 1)->previousOverviewFiledOn;
        if ($filed instanceof \DateTimeImmutable) {
            return new \DateTimeImmutable($filed->format('Y-m-d'), $zone);
        }

        return CzechDeadlines::overviewDeadline($taxYear, $this->inputs($profile, $taxYear)->returnRoute, $zone);
    }

    /**
     * The bases the advances of a year take after its overview, from the
     * year before: per month of that year's activity, in minor units, and
     * whether those months are twelve for want of anything entered.
     *
     * @return array{social: ?int, health: ?int, months: int, guessed: bool}
     */
    private function bases(ProfileView $profile, int $year): array
    {
        $inputs = $this->inputs($profile, $year);
        $months = $inputs->previousActivityMonths ?? $this->activeMonths($profile, $year - 1);

        return [
            'social' => $inputs->previousSocialBase?->minor(),
            'health' => $inputs->previousHealthBase?->minor(),
            'months' => $months ?? 12,
            'guessed' => null === $months,
        ];
    }

    /**
     * The months of a year with an activity by its statuses, or null when
     * none is entered.
     */
    private function activeMonths(ProfileView $profile, int $year): ?int
    {
        $active = 0;
        for ($month = 1; $month <= 12; ++$month) {
            $value = $profile->valueOn(StatusKind::Activity, new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month)))?->value;
            $active += null !== $value && Month::NONE !== $value ? 1 : 0;
        }

        return $active > 0 ? $active : null;
    }

    private function inputs(ProfileView $profile, int $year): YearInputsView
    {
        return $profile->years[$year] ?? new YearInputsView($year);
    }

    /**
     * @param array<string, RuleValue> $used
     */
    private function rule(string $key, \DateTimeImmutable $day, array &$used): RuleValue
    {
        $value = $this->rules->valueOn($key, $day);
        $used[$value->key.'@'.$value->validFrom->format('Y-m-d')] = $value;

        return $value;
    }
}
