<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechRuleBook;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\RulesStatus;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\Step;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * The Czech sole trader's pipeline (old T9) against the worked examples of the
 * spec and the dated rules of 2026: every figure in whole CZK.
 */
final class CzechSoleTraderTest extends TestCase
{
    public function test2027LumpSumWorkedExample(): void
    {
        $result = $this->compute(2027, $this->mainAllYear(2027), 2_000_000, 500_000);
        $lump = $result->method(ExpenseMethod::Lump60)->totals;

        self::assertSame(
            ['profit' => 800_000, 'social' => 128_480, 'health' => 54_000, 'tax' => 89_160, 'levies' => 271_640],
            ['profit' => $this->czk($lump->profit), 'social' => $this->czk($lump->social), 'health' => $this->czk($lump->health), 'tax' => $this->czk($lump->incomeTax), 'levies' => $this->czk($lump->levies)],
        );
        self::assertSame(2_000_000 - 500_000 - 271_640, $this->czk($lump->takeHome), 'The real costs count in the take-home under the lump sum too.');
        self::assertSame(RulesStatus::Derived, $result->rules);
        self::assertContains('official_2027_tables', $result->unverified);
    }

    public function test2027ActualCostsWorkedExample(): void
    {
        $result = $this->compute(2027, $this->mainAllYear(2027), 2_000_000, 500_000);
        $actual = $result->method(ExpenseMethod::Actual)->totals;

        self::assertSame(
            ['profit' => 1_500_000, 'social' => 240_900, 'health' => 101_250, 'tax' => 194_160, 'levies' => 536_310],
            ['profit' => $this->czk($actual->profit), 'social' => $this->czk($actual->social), 'health' => $this->czk($actual->health), 'tax' => $this->czk($actual->incomeTax), 'levies' => $this->czk($actual->levies)],
        );
        self::assertSame(ExpenseMethod::Lump60, $result->cheaperMethod);
        self::assertNotNull($result->breakEven);
        self::assertSame(700_000, $this->czk($result->breakEven));
    }

    public function test2026LowProfitMainAllYearPaysThe35PercentAnnualMinimum(): void
    {
        $result = $this->compute(2026, $this->mainAllYear(2026), 100_000, 0, [ExpenseMethod::Actual]);
        $totals = $result->method(ExpenseMethod::Actual)->totals;

        self::assertSame(17_139 * 12, $this->czk($totals->socialBase));
        self::assertSame(60_056, $this->czk($totals->social));
    }

    public function test2026AdvancesFollowTheirDatedMinimum(): void
    {
        $result = $this->compute(2026, $this->mainAllYear(2026), 100_000, 0, [ExpenseMethod::Actual]);

        self::assertSame('2026-01', $result->months[0]->month);
        self::assertSame(5_720, $this->czk($result->months[0]->minimumSocialAdvance));
        self::assertSame(5_720, $this->czk($result->months[5]->minimumSocialAdvance));
        self::assertSame(5_005, $this->czk($result->months[6]->minimumSocialAdvance));
        self::assertSame(3_306, $this->czk($result->months[6]->minimumHealthAdvance));
        $keys = array_map(static fn (\Foresi\TaxesAndContributions\Contract\RuleRef $rule): string => $rule->key.'@'.$rule->validFrom->format('Y-m-d'), $result->rulesUsed);
        self::assertContains('social.minimum_share.main@2026-01-01', $keys);
        self::assertContains('social.minimum_share.main@2026-07-01', $keys);
    }

    public function testVatPayerFrom12NovemberCountsGrossBeforeAndNetAfter(): void
    {
        // The actual amounts count 12,100 paid on 11 November with its VAT and 10,000 of the 12,100 paid on the 12th.
        $amounts = $this->amounts(2026, 12_100 + 10_000, 0, [AmountsView::UNVERIFIED_VAT_CASH_BASIS]);
        $profile = new ProfileView([...$this->mainAllYear(2026)->statuses, new StatusPeriod('vat', StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12'))], [], []);

        $result = new CzechSoleTrader()->compute(new RegimeInput(2026, $profile, $amounts, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-20')));

        self::assertSame(22_100, $this->czk($result->method(ExpenseMethod::Actual)->totals->income));
        self::assertCount(11, $result->months, 'To date runs January to the month of today.');
    }

    public function testSecondaryActivityBelowTheDecisiveAmountOwesNoSocial(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'secondary', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('b', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-12-01')),
        ], [], []);

        $result = $this->compute(2026, $profile, 60_000, 0, [ExpenseMethod::Actual]);
        $social = $result->method(ExpenseMethod::Actual)->step(Step::SOCIAL);

        self::assertSame('no', $social->input['secondary_takes_part']);
        self::assertSame(Czk::text(107_728 * 100), $social->input['decisive_amount'], '117,521 less 1/12 for December, which is not secondary.');
        self::assertSame(5_005, $this->czk($result->method(ExpenseMethod::Actual)->totals->social), 'Only December, the main month, takes part.');
        self::assertContains('mixed_year', $result->unverified);

        $above = $this->compute(2026, $profile, 200_000, 0, [ExpenseMethod::Actual]);
        self::assertSame('yes', $above->method(ExpenseMethod::Actual)->step(Step::SOCIAL)->input['secondary_takes_part']);
        self::assertSame(32_120, $this->czk($above->method(ExpenseMethod::Actual)->totals->social), '55 % of 200,000 is above the minimums of the months.');
    }

    public function testChildBonusNeedsIncomeOf134400AndAtLeast100(): void
    {
        $child = new ChildInput('', new \DateTimeImmutable('2020-05-01'), 1, false, 12);
        $inputs = new YearInputsView(2026, [ExpenseMethod::Lump60], children: [$child]);

        $met = $this->compute(2026, $this->mainAllYear(2026, $inputs), 134_400, 0);
        $short = $this->compute(2026, $this->mainAllYear(2026, $inputs), 134_300, 0);

        // 134,400 less 60 % is 53,760, taxed 8,064, which the taxpayer credit takes; the benefit of 15,204 is all bonus.
        self::assertSame(0, $this->czk($met->method(ExpenseMethod::Lump60)->totals->incomeTax));
        self::assertSame(15_204, $this->czk($met->method(ExpenseMethod::Lump60)->totals->childBonus));
        self::assertSame(0, $this->czk($short->method(ExpenseMethod::Lump60)->totals->childBonus), 'Below six minimum wages there is no bonus.');

        $tiny = new YearInputsView(2026, [ExpenseMethod::Lump60], children: [new ChildInput('', new \DateTimeImmutable('2020-05-01'), 1, false, 12)]);
        // 766,750 less 60 % is 306,700, taxed 46,005; the taxpayer credit leaves 15,165, so 39 of the benefit of 15,204 is left.
        $almost = $this->compute(2026, $this->mainAllYear(2026, $tiny), 766_750, 0);
        $totals = $almost->method(ExpenseMethod::Lump60)->totals;
        self::assertSame(0, $this->czk($totals->incomeTax));
        self::assertSame(0, $this->czk($totals->childBonus), 'A rest below 100 is not paid out.');
    }

    public function testTaxBaseRoundsDownToHundredsAndTaxRoundsUp(): void
    {
        $inputs = new YearInputsView(2026, [ExpenseMethod::Actual]);
        $result = $this->compute(2026, $this->mainAllYear(2026, $inputs), 300_199, 100_000);
        $method = $result->method(ExpenseMethod::Actual);

        self::assertSame(Czk::text(200_100 * 100), $method->step(Step::TAX_BASE)->result);
        self::assertSame(Czk::text(30_015 * 100), $method->step(Step::INCOME_TAX)->result);

        $odd = $this->compute(2026, $this->mainAllYear(2026, $inputs), 200_110, 100_000);
        // 100,100 x 15 % is 15,015 exactly; 100,103 rounded down is 100,100 too.
        self::assertSame(Czk::text(100_100 * 100), $odd->method(ExpenseMethod::Actual)->step(Step::TAX_BASE)->result);
        self::assertSame(Czk::text(15_015 * 100), $odd->method(ExpenseMethod::Actual)->step(Step::INCOME_TAX)->result);

        // The social contribution rounds up: 205,668 x 29.2 % is 60,055.06.
        self::assertSame(Czk::text(60_056 * 100), $odd->method(ExpenseMethod::Actual)->step(Step::SOCIAL)->result);
    }

    public function testAYearWithoutRulesUsesTheLatestAndSaysSo(): void
    {
        $result = $this->compute(2028, $this->mainAllYear(2028), 1_000_000, 0);

        self::assertSame(RulesStatus::LatestUsed, $result->rules);
        self::assertSame(2027, $result->ruleYear);
        self::assertContains('rules_not_published', $result->unverified);
        self::assertSame(51_663, (int) $this->ruleValue($result, 'average_wage'));

        $this->expectException(RulesNotAvailable::class);
        $this->compute(2025, $this->mainAllYear(2025), 1_000_000, 0);
    }

    public function testEveryStepNamesItsRuleAndASourceUrl(): void
    {
        $result = $this->compute(2026, $this->mainAllYear(2026), 900_000, 200_000);

        foreach ($result->methods as $method) {
            self::assertSame(array_values(array_diff(Step::KEYS, [Step::FLAT_RATE])), array_map(static fn (Step $step): string => $step->key, $method->steps));
            foreach ($method->steps as $step) {
                self::assertNotSame('', $step->rule, $step->key);
                self::assertStringStartsWith('https://', $step->sourceUrl, $step->key);
            }
        }
        foreach ($result->rulesUsed as $rule) {
            self::assertStringStartsWith('https://', $rule->sourceUrl, $rule->key);
        }
        foreach ($result->credits as $credit) {
            self::assertNotSame('', $credit->explanation, $credit->key);
        }
        self::assertSame(['taxpayer', 'spouse', 'disability', 'disability_third', 'ztp_p_holder', 'child_benefit'], array_map(static fn (\Foresi\TaxesAndContributions\Contract\CreditLine $credit): string => $credit->key, $result->credits));
    }

    public function testWithoutActivityEveryMonthIsMainAndTheResultSaysSo(): void
    {
        $result = $this->compute(2026, new ProfileView([], [], []), 100_000, 0, [ExpenseMethod::Actual]);

        self::assertContains('activity_assumed_main', $result->warnings);
        self::assertSame(60_056, $this->czk($result->method(ExpenseMethod::Actual)->totals->social));
    }

    public function testSicknessCountsEachMonthItIsOnAtLeastTheMinimumBase(): void
    {
        $profile = new ProfileView([...$this->mainAllYear(2026)->statuses, new StatusPeriod('s', StatusKind::SicknessInsurance, 'on', new \DateTimeImmutable('2026-10-01'), Money::ofMinor(500_000, Currency::CZK))], [], []);

        $result = $this->compute(2026, $profile, 100_000, 0, [ExpenseMethod::Actual]);

        // October to December at the minimum base of 9,000: 243 each.
        self::assertSame(729, $this->czk($result->method(ExpenseMethod::Actual)->totals->sickness));
    }

    public function testTheRuleBookDatesItsValues(): void
    {
        $rules = new CzechRuleBook();

        self::assertSame('40', $rules->valueOn('social.minimum_share.main', new \DateTimeImmutable('2026-06-30'))->value);
        self::assertSame('35', $rules->valueOn('social.minimum_share.main', new \DateTimeImmutable('2026-07-01'))->value);
        self::assertSame('48967', $rules->valueOn('average_wage', new \DateTimeImmutable('2026-12-31'))->value);
        self::assertTrue($rules->valueOn('average_wage', new \DateTimeImmutable('2027-01-01'))->unverified);
        self::assertFalse($rules->has('credit.student', new \DateTimeImmutable('2026-12-31')));
        self::assertTrue($rules->has('credit.student', new \DateTimeImmutable('2027-01-01')));
    }

    public function testTheRulesUsedAreEveryMethodsOnceSortedByKeyAndDay(): void
    {
        $result = $this->compute(2026, $this->mainAllYear(2026), 900_000, 200_000, [ExpenseMethod::Lump60, ExpenseMethod::Actual]);

        $pairs = array_map(static fn (\Foresi\TaxesAndContributions\Contract\RuleRef $rule): array => [$rule->key, $rule->validFrom->format('Y-m-d')], $result->rulesUsed);
        $sorted = $pairs;
        sort($sorted);

        self::assertSame(range(0, \count($result->rulesUsed) - 1), array_keys($result->rulesUsed), 'The rules used are a list.');
        self::assertSame($sorted, $pairs);
        self::assertSame(array_values(array_unique(array_map(static fn (array $pair): string => $pair[0].'@'.$pair[1], $pairs))), array_map(static fn (array $pair): string => $pair[0].'@'.$pair[1], $pairs), 'A value used by a step and a month is listed once.');
        self::assertContains(['expenses.lump_60.rate', '2026-01-01'], $pairs, 'The rules of the first method stay when another follows.');
        self::assertContains(['social.minimum_share.main', '2026-07-01'], $pairs);
    }

    public function testTheUnverifiedOf2026And2027AreListedOnceEach(): void
    {
        self::assertSame(['insurance_profit_definition', 'overview_deadlines', 'electronic_deadline_payment_date'], $this->compute(2026, $this->mainAllYear(2026), 900_000, 200_000)->unverified, 'The settlement on the electronic deadline waits for the authorities.');

        $mixed = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'secondary', new \DateTimeImmutable('2027-01-01')),
            new StatusPeriod('b', StatusKind::Activity, 'main', new \DateTimeImmutable('2027-12-01')),
        ], [], []);
        $result = $this->compute(2027, $mixed, 900_000, 200_000);

        self::assertSame(['insurance_profit_definition', 'mixed_year', 'overview_deadlines', 'electronic_deadline_payment_date', 'official_2027_tables', 'act_180_2026'], $result->unverified);
    }

    public function testTheWarningsAreListedOnceEach(): void
    {
        $inputs = new YearInputsView(2026, deductions: [\Foresi\TaxesAndContributions\Contract\DeductionType::UnionFees->value => Money::ofMinor(100_000, Currency::CZK)]);
        $profile = new ProfileView([
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('none', StatusKind::FlatRateBand, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2026-10-01')),
        ], [2026 => $inputs], []);

        self::assertSame(['deduction_not_computed'], $this->compute(2026, $profile, 900_000, 200_000)->warnings);

        $none = new ProfileView([$profile->statuses[0], $profile->statuses[1]], [], []);
        self::assertSame([], $this->compute(2026, $none, 900_000, 200_000)->warnings, 'The band "none" is no flat rate.');
    }

    public function testOtherStatusesWithoutAnActivityStillAssumeAMainYear(): void
    {
        $profile = new ProfileView([new StatusPeriod('s', StatusKind::SicknessInsurance, 'on', new \DateTimeImmutable('2026-01-01'))], [], []);

        $result = $this->compute(2026, $profile, 100_000, 0, [ExpenseMethod::Actual]);
        $totals = $result->method(ExpenseMethod::Actual)->totals;

        self::assertContains('activity_assumed_main', $result->warnings);
        self::assertSame(60_056, $this->czk($totals->social));
        self::assertSame(12 * 243, $this->czk($totals->sickness), 'Without a chosen base, each month at the minimum base of 9,000.');
    }

    public function testACutOffOnTheFirstOfAMonthCountsThatMonth(): void
    {
        $day = new \DateTimeImmutable('2026-11-01');
        $result = new CzechSoleTrader()->compute(new RegimeInput(2026, $this->mainAllYear(2026), $this->amounts(2026, 100_000, 0), $day, $day));

        self::assertCount(11, $result->months);
        self::assertSame('2026-11', $result->months[10]->month);
    }

    public function testAMonthWithoutActivityAsksNoAdvance(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('none', StatusKind::Activity, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-07-01')),
        ], [], []);

        $months = $this->compute(2026, $profile, 100_000, 0, [ExpenseMethod::Actual])->months;

        self::assertSame([0, 0], [$months[0]->minimumSocialAdvance->minor(), $months[0]->minimumHealthAdvance->minor()]);
        self::assertSame([5_005, 3_306], [$this->czk($months[6]->minimumSocialAdvance), $this->czk($months[6]->minimumHealthAdvance)]);
    }

    public function testTheLeviesAddSicknessAndTheTakeHomeAddsTheChildBonus(): void
    {
        $inputs = new YearInputsView(2026, [ExpenseMethod::Actual], children: [new ChildInput('', new \DateTimeImmutable('2020-05-01'), 1, false, 12)]);
        $profile = new ProfileView([...$this->mainAllYear(2026)->statuses, new StatusPeriod('s', StatusKind::SicknessInsurance, 'on', new \DateTimeImmutable('2026-01-01'))], [2026 => $inputs], []);

        $totals = $this->compute(2026, $profile, 200_000, 0)->method(ExpenseMethod::Actual)->totals;

        // Social 60,056, health 39,664 (13.5 % of 12 halves of 48,967), sickness 2,916; the tax 30,000 goes in the taxpayer credit.
        self::assertSame([60_056, 39_664, 2_916, 0, 15_204], [$this->czk($totals->social), $this->czk($totals->health), $this->czk($totals->sickness), $this->czk($totals->incomeTax), $this->czk($totals->childBonus)]);
        self::assertSame(102_636, $this->czk($totals->levies));
        self::assertSame(200_000 - 102_636 + 15_204, $this->czk($totals->takeHome));
    }

    public function testOneMethodHasNoCheaperAndEqualsKeepTheFirst(): void
    {
        self::assertNull($this->compute(2026, $this->mainAllYear(2026), 1_000_000, 600_000, [ExpenseMethod::Lump60])->cheaperMethod);

        // Costs of 60 % of the income give the same profit as the lump sum.
        $equal = $this->compute(2026, $this->mainAllYear(2026), 1_000_000, 600_000);
        self::assertSame(ExpenseMethod::Actual, $equal->cheaperMethod);
        self::assertNotNull($equal->breakEven);
        self::assertSame(0, $this->czk($equal->breakEven));
    }

    public function testTheBreakEvenTakesTheFirstOfEqualLumpSums(): void
    {
        // At 100,000 every method pays the minimums and no tax, so all leave the same.
        $result = $this->compute(2026, $this->mainAllYear(2026), 100_000, 0, [ExpenseMethod::Actual, ExpenseMethod::Lump60, ExpenseMethod::Lump40]);

        self::assertNotNull($result->breakEven);
        self::assertSame(60_000, $this->czk($result->breakEven));
    }

    /**
     * @param non-empty-list<ExpenseMethod> $methods
     */
    private function compute(int $year, ProfileView $profile, int $income, int $costs, array $methods = [ExpenseMethod::Actual, ExpenseMethod::Lump60]): RegimeResult
    {
        if (!isset($profile->years[$year])) {
            $profile = new ProfileView($profile->statuses, [$year => new YearInputsView($year, $methods)], []);
        }
        $end = new \DateTimeImmutable(\sprintf('%04d-12-31', $year));

        return new CzechSoleTrader()->compute(new RegimeInput($year, $profile, $this->amounts($year, $income, $costs), $end, $end));
    }

    private function mainAllYear(int $year, ?YearInputsView $inputs = null): ProfileView
    {
        return new ProfileView(
            [new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable(\sprintf('%04d-01-01', $year)))],
            $inputs instanceof YearInputsView ? [$year => $inputs] : [],
            [],
        );
    }

    /**
     * @param list<string> $unverified
     */
    private function amounts(int $year, int $income, int $costs, array $unverified = []): AmountsView
    {
        $money = static fn (int $czk): Money => Money::ofMinor($czk * 100, Currency::CZK);
        $totals = static fn (int $czk): AmountTotals => new AmountTotals($money($czk), $money($czk), $money(0), $money($czk));

        return new AmountsView(
            new \DateTimeImmutable(\sprintf('%04d-01-01', $year)),
            new \DateTimeImmutable(\sprintf('%04d-12-31', $year)),
            [],
            [AmountKind::Income->value => $totals($income), AmountKind::Cost->value => $totals($costs)],
            [],
            [],
            [],
            [],
            $unverified,
            [],
        );
    }

    private function czk(Money $money): int
    {
        return intdiv($money->minor(), 100);
    }

    private function ruleValue(RegimeResult $result, string $key): string
    {
        foreach ($result->rulesUsed as $rule) {
            if ($key === $rule->key) {
                return $rule->value;
            }
        }

        self::fail('No rule '.$key);
    }
}
