<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechFlatRate;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2026;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\FlatRateStep;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\FlatRateMonth;
use Foresi\TaxesAndContributions\Contract\FlatRateResult;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\Step;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * The flat-rate tax as a regime option (spec phase 1): a month whose band is
 * 1, 2 or 3 on its first day pays the band's amount, which replaces social,
 * health and income tax, due by the 20th; becoming a VAT payer ends it at the
 * end of that year. Every figure in whole CZK.
 */
final class CzechFlatRateTest extends TestCase
{
    public function testBand1In2026Is9984ToJuneAnd9162FromJuly(): void
    {
        $flat = $this->flatRate($this->compute(2026, $this->band('1', 2026), 600_000));

        self::assertSame(array_merge(array_fill(0, 6, 9_984), array_fill(0, 6, 9_162)), array_map(fn (FlatRateMonth $month): int => $this->czk($month->total()), $flat->months));
        self::assertSame([100, 6_578, 3_306], $this->parts($flat->months[5]));
        self::assertSame([100, 5_756, 3_306], $this->parts($flat->months[6]));
        self::assertSame([1_200, 74_004, 39_672, 114_876], [$this->czk($flat->incomeTax), $this->czk($flat->social), $this->czk($flat->health), $this->czk($flat->total)]);
    }

    public function testBands2And3In2026AreTheSameAllYear(): void
    {
        $two = $this->flatRate($this->compute(2026, $this->band('2', 2026), 1_200_000));
        $three = $this->flatRate($this->compute(2026, $this->band('3', 2026), 1_800_000));

        self::assertSame([[4_963, 8_191, 3_591], 16_745 * 12], [$this->parts($two->months[11]), $this->czk($two->total)]);
        self::assertSame([[9_320, 12_527, 5_292], 27_139 * 12], [$this->parts($three->months[0]), $this->czk($three->total)]);
    }

    public function testBand1In2027Is9661AndUnverified(): void
    {
        $result = $this->compute(2027, $this->band('1', 2027), 600_000);

        self::assertSame([100, 6_073, 3_488], $this->parts($this->flatRate($result)->months[0]));
        self::assertSame(9_661 * 12, $this->czk($this->flatRate($result)->total));
        self::assertContains(CzechFlatRate::UNVERIFIED_2027_BAND_1, $result->unverified);
        self::assertContains(CzechSoleTrader::GAP_FLAT_RATE_SURCHARGE, $result->knownGaps, 'The surcharge of Act 180/2026 is not counted.');
        self::assertNotContains(CzechFlatRate::UNVERIFIED_2027_BAND_1, $this->compute(2027, $this->band('2', 2027), 1_200_000)->unverified, 'Bands 2 and 3 stay as they were.');
        self::assertNotContains(CzechSoleTrader::GAP_FLAT_RATE_SURCHARGE, $this->compute(2026, $this->band('1', 2026), 600_000)->knownGaps);
        self::assertNotContains(CzechFlatRate::UNVERIFIED_2027_BAND_1, $this->compute(2026, $this->band('1', 2026), 600_000)->unverified);

        // Before 2027 starts, its advances alone say so.
        $today = new \DateTimeImmutable('2026-11-20');
        $ahead = new CzechSoleTrader()->compute(new RegimeInput(2027, $this->band('1', 2027), AmountsView::none($today, $today), $today, $today));
        self::assertSame([[], 966_100], [$ahead->months, $this->ofAuthority($ahead, Authority::FlatRate)[0]->amount->minor()]);
        self::assertContains(CzechFlatRate::UNVERIFIED_2027_BAND_1, $ahead->unverified);
    }

    public function testFlatRateMonthsHaveNoSocialHealthOrIncomeTaxSteps(): void
    {
        $inputs = new YearInputsView(2026, [ExpenseMethod::Actual, ExpenseMethod::Lump60], children: [new ChildInput('', new \DateTimeImmutable('2020-05-01'), 1, false, 12)], employmentBase: Money::ofMinor(10_000_000, Currency::of('CZK')));
        $profile = new ProfileView([...$this->band('1', 2026)->statuses, new StatusPeriod('s', StatusKind::SicknessInsurance, 'on', new \DateTimeImmutable('2026-01-01'))], [2026 => $inputs], []);

        $result = $this->compute(2026, $profile, 600_000, 100_000);
        $actual = $result->method(ExpenseMethod::Actual);
        $totals = $actual->totals;

        self::assertSame([0, 0, 0, 0], [$this->czk($totals->social), $this->czk($totals->health), $this->czk($totals->incomeTax), $this->czk($totals->childBonus)]);
        self::assertSame([114_876, 12 * 243], [$this->czk($totals->flatRate), $this->czk($totals->sickness)], 'Voluntary sickness insurance is paid apart.');
        self::assertSame(114_876 + 2_916, $this->czk($totals->levies));
        self::assertSame(600_000 - 100_000 - 114_876 - 2_916, $this->czk($totals->takeHome));
        self::assertSame([], $result->credits, 'No credit or child benefit applies.');
        foreach ([Step::SOCIAL, Step::HEALTH, Step::TAX_BASE, Step::INCOME_TAX, Step::CREDITS, Step::CHILD_BENEFIT] as $key) {
            self::assertSame([['flat_rate_months' => '12'], '0.00'], [$actual->step($key)->input, $actual->step($key)->result], $key);
        }
        self::assertSame(Step::KEYS, array_map(static fn (Step $step): string => $step->key, $actual->steps));
        $step = $actual->step(Step::FLAT_RATE);
        self::assertSame(['band_1_months' => '12', 'income_tax_part' => '1200.00', 'social_part' => '74004.00', 'health_part' => '39672.00', 'income' => '600000.00', 'income_limit' => '1000000.00'], $step->input);
        self::assertSame([Rules2026::FLAT_RATE, '114876.00'], [$step->sourceUrl, $step->result]);
        self::assertSame(['1', 6_578, 3_306], [$result->months[0]->flatRateBand, $this->czk($result->months[0]->minimumSocialAdvance), $this->czk($result->months[0]->minimumHealthAdvance)]);
        self::assertSame($totals->levies->minor(), $result->method(ExpenseMethod::Lump60)->totals->levies->minor(), 'The flat rate does not depend on the expense method.');
        self::assertSame([], $this->ofAuthority($result, Authority::Social, Authority::Health, Authority::IncomeTax), 'No ordinary advance and no settlement.');
    }

    public function testAMixedYearCountsTheOrdinaryMonthsWithTheirShareOfTheProfit(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('none', StatusKind::FlatRateBand, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '2', new \DateTimeImmutable('2026-07-01')),
        ], [2026 => new YearInputsView(2026, [ExpenseMethod::Actual])], []);

        $result = $this->compute(2026, $profile, 1_200_000, 0);
        $actual = $result->method(ExpenseMethod::Actual);

        self::assertContains('flat_rate_mixed_year', $result->unverified);
        self::assertSame(16_745 * 6, $this->czk($actual->totals->flatRate));
        self::assertSame(['600000.00', '6', '0'], [$actual->step(Step::SOCIAL)->input['profit'], $actual->step(Step::SOCIAL)->input['main_months'], $actual->step(Step::SOCIAL)->input['secondary_months']], 'Half of the profit for the six ordinary months.');
        self::assertSame('6', $actual->step(Step::HEALTH)->input['active_months']);
        // 55 % of 600,000 = 330,000 is above 6 minimums of 17,139; times 29.2 %.
        self::assertSame(96_360, $this->czk($actual->totals->social));
        self::assertSame([null, '2'], [$result->months[5]->flatRateBand, $result->months[6]->flatRateBand]);
        self::assertSame(['2026-06', '2026-07'], [$this->ofAuthority($result, Authority::Social)[5]->period ?? null, $this->ofAuthority($result, Authority::FlatRate)[0]->period]);
        self::assertCount(6, $this->advances($this->ofAuthority($result, Authority::Social)), 'Social advances only for the ordinary months.');
        self::assertSame('2026-08-20', $this->ofAuthority($result, Authority::FlatRate)[0]->dueOn->format('Y-m-d'), 'The first month of the regime is due on the 20th of the next.');
        self::assertSame(Rules2026::FLAT_RATE_PARTS, $actual->step(Step::FLAT_RATE)->sourceUrl, 'The source of the first flat-rate month.');

        // Six months in the flat rate, then six ordinary ones.
        $after = new ProfileView([$profile->statuses[0], new StatusPeriod('band', StatusKind::FlatRateBand, '2', new \DateTimeImmutable('2026-01-01')), new StatusPeriod('none', StatusKind::FlatRateBand, 'none', new \DateTimeImmutable('2026-07-01'))], $profile->years, []);
        $months = $this->compute(2026, $after, 1_200_000)->months;
        self::assertCount(12, $months);
        self::assertSame(['2', null, 5_005], [$months[5]->flatRateBand, $months[11]->flatRateBand, $this->czk($months[11]->minimumSocialAdvance)]);

        // Months without an activity are no share: three ordinary and three flat-rate months take half each.
        $late = new ProfileView([
            new StatusPeriod('none', StatusKind::Activity, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-07-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2026-10-01')),
        ], $profile->years, []);
        self::assertSame('600000.00', $this->compute(2026, $late, 1_200_000)->method(ExpenseMethod::Actual)->step(Step::SOCIAL)->input['profit']);

        // A loss gives no negative share of the profit.
        self::assertSame('0.00', $this->compute(2026, $profile, 0, 100_000)->method(ExpenseMethod::Actual)->step(Step::HEALTH)->input['profit_share']);
    }

    public function testBecomingAVatPayerEndsTheFlatRateAtYearEndWithAWarning(): void
    {
        $profile = new ProfileView([
            ...$this->band('1', 2026)->statuses,
            new StatusPeriod('vat', StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12')),
        ], [], []);

        $thisYear = $this->compute(2026, $profile, 600_000);
        self::assertCount(12, $this->flatRate($thisYear)->months, 'The flat rate holds to 31 December.');
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDS, $thisYear->warnings);
        self::assertNotContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDED, $thisYear->warnings);

        $nextYear = $this->compute(2027, $profile, 600_000);
        self::assertNull($nextYear->flatRate, 'The band stays in the profile, yet next year is ordinary.');
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDED, $nextYear->warnings);
        self::assertNull($nextYear->months[0]->flatRateBand);
        self::assertCount(12, $this->advances($this->ofAuthority($nextYear, Authority::Social)));
        self::assertSame([], $this->ofAuthority($nextYear, Authority::FlatRate));

        $before = new ProfileView([
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('vat', StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-03-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-01-01')),
        ], [], []);
        self::assertNull($this->compute(2027, $before, 600_000)->flatRate, 'A VAT payer when the year starts is not in the flat rate.');
        $leftOnTheSecond = new ProfileView([...$before->statuses, new StatusPeriod('no', StatusKind::VatPayer, 'no', new \DateTimeImmutable('2027-01-02'))], [], []);
        self::assertNull($this->compute(2027, $leftOnTheSecond, 600_000)->flatRate, 'On 1 January the owner is still a VAT payer.');

        $sameDay = $this->compute(2027, new ProfileView([...$this->band('1', 2027)->statuses, new StatusPeriod('vat', StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2027-01-01'))], [], []), 600_000);
        self::assertNull($sameDay->flatRate);
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDED, $sameDay->warnings);
        self::assertNotContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDS, $sameDay->warnings, 'What has ended does not end again.');

        $starting = new ProfileView([
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2026-04-01')),
            new StatusPeriod('vat', StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-04-01')),
        ], [], []);
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDS, $this->compute(2026, $starting, 600_000)->warnings, 'A VAT start on the band day counts.');

        $other = new ProfileView([
            ...$this->band('1', 2026)->statuses,
            new StatusPeriod('no', StatusKind::VatPayer, 'no', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('exempt', StatusKind::HealthMinimumExempt, 'yes', new \DateTimeImmutable('2026-02-01')),
        ], [], []);
        $ordinary = $this->compute(2026, $other, 600_000);
        self::assertSame([[], 12], [array_values(array_intersect([CzechSoleTrader::WARNING_FLAT_RATE_ENDS, CzechSoleTrader::WARNING_FLAT_RATE_ENDED], $ordinary->warnings)), \count($this->flatRate($ordinary)->months)], 'Only a VAT payer ends it.');
    }

    public function testIncomeAboveTheBandLimitWarnsWithoutRefusing(): void
    {
        $over = $this->compute(2026, $this->band('1', 2026), 1_000_001);

        self::assertContains(FlatRateStep::WARNING_OVER_INCOME_LIMIT, $over->warnings);
        self::assertSame(114_876, $this->czk($this->flatRate($over)->total));
        self::assertNotContains(FlatRateStep::WARNING_OVER_INCOME_LIMIT, $this->compute(2026, $this->band('1', 2026), 1_000_000)->warnings);
        self::assertNotContains(FlatRateStep::WARNING_OVER_INCOME_LIMIT, $this->compute(2026, $this->band('3', 2026), 2_000_000)->warnings);
    }

    public function testFlatRatePaymentsAreDueOnThe20th(): void
    {
        $profile = new ProfileView($this->band('1', 2026)->statuses, [2026 => new YearInputsView(2026, [ExpenseMethod::Actual], lastKnownTax: Money::ofMinor(10_000_000, Currency::of('CZK')))], []);

        $result = $this->compute(2026, $profile, 600_000);
        $flat = $this->ofAuthority($result, Authority::FlatRate);

        self::assertCount(12, $flat);
        self::assertSame(['2026-01-20', DuePayment::KIND_ADVANCE, '2026-01', '2026-01-01', 998_400], [$flat[0]->dueOn->format('Y-m-d'), $flat[0]->kind, $flat[0]->period, $flat[0]->payableFrom->format('Y-m-d'), $flat[0]->amount->minor()]);
        self::assertSame('2026-06-22', $flat[5]->dueOn->format('Y-m-d'), 'Saturday 20 June moves to Monday.');
        self::assertSame(['2026-12-21', 916_200], [$flat[11]->dueOn->format('Y-m-d'), $flat[11]->amount->minor()], 'Sunday 20 December moves to Monday.');
        self::assertSame([], $this->ofAuthority($result, Authority::Social, Authority::Health, Authority::IncomeTax), 'The last known tax asks no income tax advance of a flat-rate year.');
        self::assertNotContains('middle_advance_band', $result->unverified);
    }

    public function testTheFirstMonthOfTheRegimeIsDueOnThe20thOfTheNextMonth(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('none', StatusKind::Activity, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-04-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2026-04-01')),
        ], [], []);

        $flat = $this->ofAuthority($this->compute(2026, $profile, 300_000), Authority::FlatRate);

        self::assertSame(['2026-04', '2026-05-20'], [$flat[0]->period, $flat[0]->dueOn->format('Y-m-d')]);
        self::assertSame(['2026-05', '2026-05-20'], [$flat[1]->period, $flat[1]->dueOn->format('Y-m-d')]);
        self::assertCount(9, $flat, 'A band without an activity pays nothing.');
    }

    public function testAnOrdinaryYearHasNoFlatRate(): void
    {
        $result = $this->compute(2026, new ProfileView([new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01'))], [], []), 600_000);

        self::assertNull($result->flatRate);
        self::assertSame(0, $result->method(ExpenseMethod::Actual)->totals->flatRate->minor());
        self::assertFalse($result->method(ExpenseMethod::Actual)->hasStep(Step::FLAT_RATE));
        self::assertNull($result->months[0]->flatRateBand);
    }

    private function compute(int $year, ProfileView $profile, int $income, int $costs = 0): RegimeResult
    {
        if (!isset($profile->years[$year])) {
            $profile = new ProfileView($profile->statuses, [...$profile->years, $year => new YearInputsView($year, [ExpenseMethod::Actual, ExpenseMethod::Lump60])], []);
        }
        $end = new \DateTimeImmutable(\sprintf('%04d-12-31', $year));
        $money = static fn (int $czk): Money => Money::ofMinor($czk * 100, Currency::of('CZK'));
        $totals = static fn (int $czk): AmountTotals => new AmountTotals($money($czk), $money($czk), $money(0), $money($czk));
        $amounts = new AmountsView(new \DateTimeImmutable(\sprintf('%04d-01-01', $year)), $end, [], [AmountKind::Income->value => $totals($income), AmountKind::Cost->value => $totals($costs)], [], [], [], [], [], []);

        return new CzechSoleTrader()->compute(new RegimeInput($year, $profile, $amounts, $end, $end));
    }

    private function band(string $band, int $year): ProfileView
    {
        $first = new \DateTimeImmutable(\sprintf('%04d-01-01', $year));

        return new ProfileView([
            new StatusPeriod('main', StatusKind::Activity, 'main', $first),
            new StatusPeriod('band', StatusKind::FlatRateBand, $band, $first),
        ], [], []);
    }

    private function flatRate(RegimeResult $result): FlatRateResult
    {
        return $result->flatRate ?? self::fail('No flat rate.');
    }

    /**
     * @return list<DuePayment>
     */
    private function ofAuthority(RegimeResult $result, Authority ...$authorities): array
    {
        return array_values(array_filter($result->payments, static fn (DuePayment $payment): bool => \in_array($payment->authority, $authorities, true)));
    }

    /**
     * @param list<DuePayment> $payments
     *
     * @return list<DuePayment>
     */
    private function advances(array $payments): array
    {
        return array_values(array_filter($payments, static fn (DuePayment $payment): bool => DuePayment::KIND_ADVANCE === $payment->kind));
    }

    /**
     * @return array{int, int, int}
     */
    private function parts(FlatRateMonth $month): array
    {
        return [$this->czk($month->incomeTax), $this->czk($month->social), $this->czk($month->health)];
    }

    private function czk(Money $money): int
    {
        return intdiv($money->minor(), 100);
    }
}
