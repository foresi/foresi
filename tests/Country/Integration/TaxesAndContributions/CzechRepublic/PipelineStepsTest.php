<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechRuleBook;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Month;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ChildBenefitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\CreditsStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\HealthStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\IncomeTaxStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\ProfitStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SicknessStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\SocialStep;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps\TaxBaseStep;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\CreditLine;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * Each step of the Czech sole trader's pipeline on its own, from a calculation
 * the test fills in: the inputs it reports, its boundaries and its roundings,
 * by the rules of 2026. Amounts in the assertions are in whole CZK unless a
 * text says otherwise.
 */
final class PipelineStepsTest extends TestCase
{
    public function testProfitStepReportsItsInputsAndNeverClaimsANegativeLumpSum(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year(), ExpenseMethod::Lump60, -1_000_000, 300_000);

        $step = new ProfitStep()->apply($calculation);

        self::assertSame(['income' => '-10000.00', 'costs' => '3000.00', 'method' => 'lump_60', 'lump_sum_rate' => '60', 'lump_sum_cap' => '1200000.00', 'expenses' => '0.00'], $step->input);
        self::assertSame('-10000.00', $step->result, 'A negative income claims no lump sum.');

        $none = $this->calculation(new YearInputsView(2026), $this->year(), ExpenseMethod::Lump60);
        self::assertSame('0.00', new ProfitStep()->apply($none)->input['expenses']);
    }

    public function testSocialStepReportsTheMinimumOfAMainYearWithALoss(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year());
        $calculation->profit = $this->minor(-10_000);

        $step = new SocialStep()->apply($calculation);

        // 35 % of 48,967 is 17,138.45, up to 17,139 a month; 205,668 x 29.2 % is 60,055.06, up to 60,056.
        self::assertSame([
            'profit' => '-10000.00',
            'main_months' => '12',
            'secondary_months' => '0',
            'profit_share' => '0.00',
            'minimum_base' => '205668.00',
            'maximum_base' => '2350416.00',
            'base' => '205668.00',
            'rate' => '29.2',
        ], $step->input);
        self::assertSame('60056.00', $step->result);
        self::assertSame([], $calculation->unverified, 'A main year alone is not a mixed year.');
    }

    public function testSocialStepTakesASecondaryYearInAtExactlyTheDecisiveAmount(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year(Month::SECONDARY));
        $calculation->profit = $this->minor(117_521);

        $step = new SocialStep()->apply($calculation);

        // 11 % of 48,967 is 5,386.37, up to 5,387 a month: 64,644, above 55 % of the profit (64,636.55).
        self::assertSame('117521.00', $step->input['decisive_amount']);
        self::assertSame('yes', $step->input['secondary_takes_part']);
        self::assertSame('64644.00', $step->input['base']);
        self::assertSame('18877.00', $step->result);
    }

    public function testHealthStepCountsTheMinimumOfEachActiveMonthWithoutTheExemption(): void
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[] = $this->month($number, $number <= 3 ? Month::NONE : Month::MAIN, 4 === $number);
        }
        $calculation = $this->calculation(new YearInputsView(2026), $months);

        $step = new HealthStep()->apply($calculation);

        // Eight months at half of 48,967: 195,868; times 13.5 % is 26,442.18, up to 26,443.
        self::assertSame([
            'profit' => '0.00',
            'active_months' => '9',
            'months_with_minimum' => '8',
            'profit_share' => '0.00',
            'minimum_base' => '195868.00',
            'base' => '195868.00',
            'rate' => '13.5',
        ], $step->input);
        self::assertSame('26443.00', $step->result);
    }

    public function testHealthStepWithEveryMonthExemptAndALossOwesNothing(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year(Month::MAIN, true));
        $calculation->profit = $this->minor(-50_000);

        $step = new HealthStep()->apply($calculation);

        self::assertSame('0.00', $step->result);
        self::assertSame(0, $calculation->health);
    }

    public function testSicknessStepTakesTheChosenBaseAtLeastTheMinimumForActiveMonths(): void
    {
        $months = [
            $this->month(9, Month::NONE, sickness: true, base: $this->minor(20_000)),
            $this->month(10, Month::MAIN, sickness: true),
            $this->month(11, Month::MAIN, sickness: true, base: $this->minor(20_000)),
            $this->month(12, Month::MAIN, sickness: true, base: $this->minor(5_000)),
        ];
        $calculation = $this->calculation(new YearInputsView(2026), $months);

        $step = new SicknessStep()->apply($calculation);

        // 9,000 x 2.7 % is 243 and 20,000 x 2.7 % is 540; September has no activity.
        self::assertSame(['months' => '3', 'bases' => '2026-10 9000.00, 2026-11 20000.00, 2026-12 9000.00', 'rate' => '2.7'], $step->input);
        self::assertSame('1026.00', $step->result);
    }

    public function testTaxBaseStepAddsThePartialBasesAndDeductsWithinTheCaps(): void
    {
        $inputs = new YearInputsView(
            2026,
            deductions: [
                DeductionType::PensionProducts->value => $this->money(10_000),
                DeductionType::LifeInsurance->value => $this->money(7_000),
                DeductionType::LongTermInvestment->value => $this->money(5_000),
                DeductionType::HousingLoanInterest->value => $this->money(30_000),
                DeductionType::Gifts->value => $this->money(1_000),
            ],
            employmentBase: $this->money(100_000),
            rentalBase: $this->money(20_000),
            otherBase: $this->money(3_000),
        );
        $calculation = $this->calculation($inputs, $this->year());
        $calculation->profit = $this->minor(500_000);

        $step = new TaxBaseStep()->apply($calculation);

        // Gifts of exactly 1,000 count although they are below 2 % of 623,000.
        self::assertSame([
            'profit' => '500000.00',
            'other_partial_bases' => '123000.00',
            'pension_and_life' => '22000.00',
            'housing_loan_interest' => '30000.00',
            'gifts' => '1000.00',
            'deductions' => '53000.00',
        ], $step->input);
        self::assertSame('570000.00', $step->result);
        self::assertSame([], $calculation->warnings);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function gifts(): iterable
    {
        yield 'above 2 % of the base' => [40_000, 900, '900.00'];
        yield 'exactly 2 % of the base, below 1,000' => [40_000, 800, '0.00'];
        yield 'below both' => [1_000_000, 500, '0.00'];
        yield 'at least 1,000, below 2 %' => [1_000_000, 5_000, '5000.00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gifts')]
    public function testTaxBaseStepCountsGiftsFrom1000OrAbove2PercentOfTheBase(int $profit, int $gifts, string $counted): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, deductions: [DeductionType::Gifts->value => $this->money($gifts)]), $this->year());
        $calculation->profit = $this->minor($profit);

        $step = new TaxBaseStep()->apply($calculation);

        self::assertSame($counted, $step->input['gifts']);
        self::assertSame('0.00', $step->input['other_partial_bases']);
    }

    public function testTaxBaseStepWithALossDeductsNothingAndStaysAtZero(): void
    {
        $inputs = new YearInputsView(2026, deductions: [DeductionType::Gifts->value => $this->money(5_000), DeductionType::PensionProducts->value => $this->money(10_000)]);
        $calculation = $this->calculation($inputs, $this->year());
        $calculation->profit = $this->minor(-100_000);

        $step = new TaxBaseStep()->apply($calculation);

        self::assertSame('0.00', $step->input['gifts']);
        self::assertSame('10000.00', $step->input['pension_and_life']);
        self::assertSame('0.00', $step->input['deductions']);
        self::assertSame('0.00', $step->result);
    }

    public function testTaxBaseStepWarnsOfExamFeesItDoesNotCompute(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, deductions: [DeductionType::ExamFees->value => $this->money(2_000)]), $this->year());

        new TaxBaseStep()->apply($calculation);

        self::assertSame([Calculation::WARNING_DEDUCTION_NOT_COMPUTED], $calculation->warnings);
    }

    public function testIncomeTaxStepTaxesThePartAbove36AverageWagesAt23Percent(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year());
        $calculation->taxBase = $this->minor(2_000_000);

        $step = new IncomeTaxStep()->apply($calculation);

        // 15 % of 1,762,812 is 264,421.80 and 23 % of 237,188 is 54,553.24: 318,975.04, up to 318,976.
        self::assertSame(['tax_base' => '2000000.00', 'threshold' => '1762812.00', 'rate' => '15', 'higher_rate' => '23'], $step->input);
        self::assertSame('318976.00', $step->result);
    }

    public function testCreditsStepClaimsTheTaxpayerAndTheSpouseForTheirMonths(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, spouse: new SpouseInput(6, true, true)), $this->year());
        $calculation->tax = $this->minor(100_000);

        $step = new CreditsStep()->apply($calculation);

        // Half a year of 24,840, doubled with the ZTP/P card.
        self::assertSame(['tax' => '100000.00', 'taxpayer' => '30840.00', 'spouse' => '24840.00', 'claimed' => '55680.00', 'applied' => '55680.00'], $step->input);
        self::assertSame('44320.00', $step->result);
        self::assertSame(
            [['taxpayer', true, 3_084_000], ['spouse', true, 2_484_000], ['disability', false, 0], ['disability_third', false, 0], ['ztp_p_holder', false, 0]],
            array_map(static fn (CreditLine $line): array => [$line->key, $line->claimed, $line->amount->minor()], $calculation->credits),
        );
    }

    public function testCreditsStepCountsTheSpouseForAtMost12Months(): void
    {
        foreach ([12, 13] as $months) {
            $calculation = $this->calculation(new YearInputsView(2026, spouse: new SpouseInput($months, false, true)), $this->year());
            $calculation->tax = $this->minor(100_000);

            self::assertSame('24840.00', new CreditsStep()->apply($calculation)->input['spouse'], $months.' months');
        }
    }

    public function testCreditsStepGivesNoSpouseCreditWithoutMonthsOrWithTheirOwnIncome(): void
    {
        foreach ([new SpouseInput(0, false, true), new SpouseInput(12, false, false)] as $spouse) {
            $calculation = $this->calculation(new YearInputsView(2026, spouse: $spouse), $this->year());
            $calculation->tax = $this->minor(10_000);

            $step = new CreditsStep()->apply($calculation);

            self::assertSame('0.00', $step->input['spouse']);
            self::assertSame('0.00', $step->result, 'The credit lowers the tax to zero, never below.');
            self::assertFalse($calculation->credits[1]->claimed);
        }
    }

    public function testChildBenefitStepRatesEachChildByItsOrderAndMonths(): void
    {
        $children = [
            $this->child(2, 12, true),
            $this->child(1, 12),
            $this->child(3, 6),
            $this->child(5, 0),
        ];
        $calculation = $this->calculation(new YearInputsView(2026, children: $children), $this->year());
        $calculation->income = $this->minor(1_000_000);

        $step = new ChildBenefitStep()->apply($calculation);

        // 22,320 doubled, 15,204, and half of 27,840; a child of no month gives nothing.
        self::assertSame('4', $step->input['children']);
        self::assertSame('73764.00', $step->input['benefit']);
        self::assertSame('73764.00', $step->input['bonus']);
        self::assertCount(1, $calculation->credits);
        $line = $calculation->credits[0];
        self::assertSame(['child_benefit', true, 7_376_400], [$line->key, $line->claimed, $line->amount->minor()]);
    }

    public function testChildBenefitStepKeepsTheMonthsWithin0And12(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, children: [$this->child(1, 13), $this->child(1, -1)]), $this->year());

        self::assertSame('15204.00', new ChildBenefitStep()->apply($calculation)->input['benefit']);
    }

    public function testChildBenefitStepPaysARestOfExactly100(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, children: [$this->child(1, 12)]), $this->year());
        $calculation->income = $this->minor(134_400);
        $calculation->taxAfterCredits = $this->minor(15_104);

        $step = new ChildBenefitStep()->apply($calculation);

        self::assertSame('15104.00', $step->input['as_credit']);
        self::assertSame(10_000, $calculation->childBonus);
        self::assertSame('0.00', $step->result);
    }

    public function testChildBenefitStepNeedsTheIncomeOfSixMinimumWagesToTheHaler(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, children: [$this->child(1, 12)]), $this->year());
        $calculation->income = $this->minor(134_400) - 1;

        $step = new ChildBenefitStep()->apply($calculation);

        self::assertSame('134399.99', $step->input['income']);
        self::assertSame('134400.00', $step->input['minimum_income']);
        self::assertSame(0, $calculation->childBonus);
    }

    public function testChildBenefitStepCountsTheEmploymentIncomeTowardsTheBonus(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026, children: [$this->child(1, 12)], employmentBase: $this->money(34_400)), $this->year());
        $calculation->income = $this->minor(100_000);

        $step = new ChildBenefitStep()->apply($calculation);

        self::assertSame('134400.00', $step->input['income']);
        self::assertSame($this->minor(15_204), $calculation->childBonus);
    }

    public function testChildBenefitStepWithoutChildrenClaimsNothing(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026), $this->year());

        $step = new ChildBenefitStep()->apply($calculation);

        self::assertSame('0', $step->input['children']);
        self::assertFalse($calculation->credits[0]->claimed);
    }

    /**
     * @param list<Month> $months
     */
    private function calculation(YearInputsView $inputs, array $months, ExpenseMethod $method = ExpenseMethod::Actual, int $income = 0, int $costs = 0): Calculation
    {
        $money = static fn (int $minor): Money => Money::ofMinor($minor, Currency::CZK);
        $totals = static fn (int $minor): AmountTotals => new AmountTotals($money($minor), $money($minor), $money(0), $money($minor));
        $amounts = new AmountsView(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), [], [AmountKind::Income->value => $totals($income), AmountKind::Cost->value => $totals($costs)], [], [], [], [], [], []);
        $end = new \DateTimeImmutable('2026-12-31');
        $input = new RegimeInput(2026, new ProfileView([], [2026 => $inputs], []), $amounts, $end, $end);

        return new Calculation($input, $inputs, $method, $months, new CzechRuleBook());
    }

    /**
     * @return list<Month>
     */
    private function year(string $activity = Month::MAIN, bool $exempt = false): array
    {
        return array_map(fn (int $number): Month => $this->month($number, $activity, $exempt), range(1, 12));
    }

    private function month(int $number, string $activity, bool $exempt = false, bool $sickness = false, ?int $base = null): Month
    {
        return new Month(new \DateTimeImmutable(\sprintf('2026-%02d-01', $number)), $activity, $exempt, $sickness, $base);
    }

    private function child(int $order, int $months, bool $ztpP = false): ChildInput
    {
        return new ChildInput('', new \DateTimeImmutable('2020-05-01'), $order, $ztpP, $months);
    }

    private function money(int $czk): Money
    {
        return Money::ofMinor($this->minor($czk), Currency::CZK);
    }

    private function minor(int $czk): int
    {
        return $czk * 100;
    }
}
