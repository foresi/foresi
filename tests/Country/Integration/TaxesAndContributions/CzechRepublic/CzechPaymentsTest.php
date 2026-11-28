<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechDeadlines;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechPayments;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\RuleRef;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * When a Czech sole trader pays what (old T7): the advances of each month by
 * the rules and statuses of that month, the income tax advances by the last
 * known tax, and the settlement in the next year by the return route, with
 * the deadlines on working days.
 */
final class CzechPaymentsTest extends TestCase
{
    private const string ZONE = 'Europe/Prague';

    public function testSocialAdvanceDueLastDayAndHealthOnThe8thOfNextMonth(): void
    {
        $result = $this->compute($this->profile('main'));

        self::assertSame(['2026-12-31', 500_500], $this->dueAndAmount($result, 'social.advance.2026-12'));
        self::assertSame(['2027-01-08', 330_600], $this->dueAndAmount($result, 'health.advance.2026-12'));
        self::assertSame(['2026-12-01', '2026-12-01'], [$this->payment($result, 'social.advance.2026-12')->payableFrom->format('Y-m-d'), $this->payment($result, 'health.advance.2026-12')->payableFrom->format('Y-m-d')]);
        self::assertSame(self::ZONE, $this->payment($result, 'social.advance.2026-12')->dueOn->getTimezone()->getName());
    }

    public function testThe2026SocialAdvancesAre5720ToJuneAnd5005FromJuly(): void
    {
        $result = $this->compute($this->profile('main'));

        $social = array_map(fn (int $month): int => $this->payment($result, \sprintf('social.advance.2026-%02d', $month))->amount->minor(), range(1, 12));

        self::assertSame([...array_fill(0, 6, 572_000), ...array_fill(0, 6, 500_500)], $social);
        self::assertSame(array_fill(0, 12, 330_600), array_map(fn (int $month): int => $this->payment($result, \sprintf('health.advance.2026-%02d', $month))->amount->minor(), range(1, 12)));
    }

    public function testLastKnownTax200000GivesFourAdvancesOf50000(): void
    {
        $result = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(200_000))));

        $tax = $this->of($result, Authority::IncomeTax, DuePayment::KIND_ADVANCE);

        // 15 March 2026 is a Sunday: the deadline moves to Monday.
        self::assertSame(['2026-03-16', '2026-06-15', '2026-09-15', '2026-12-15'], array_map(static fn (DuePayment $payment): string => $payment->dueOn->format('Y-m-d'), $tax));
        self::assertSame([5_000_000], array_values(array_unique(array_map(static fn (DuePayment $payment): int => $payment->amount->minor(), $tax))));
        self::assertSame(['2026-01-01', '2026-03-17', '2026-06-16', '2026-09-16'], array_map(static fn (DuePayment $payment): string => $payment->payableFrom->format('Y-m-d'), $tax));
        self::assertNotContains(CzechPayments::UNVERIFIED_MIDDLE_BAND, $result->unverified);
    }

    public function testLastKnownTax100000GivesTwoAdvancesOf40000Flagged(): void
    {
        $result = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(100_000))));

        $tax = $this->of($result, Authority::IncomeTax, DuePayment::KIND_ADVANCE);

        self::assertSame([['2026-06-15', 4_000_000], ['2026-12-15', 4_000_000]], array_map(static fn (DuePayment $payment): array => [$payment->dueOn->format('Y-m-d'), $payment->amount->minor()], $tax));
        self::assertContains(CzechPayments::UNVERIFIED_MIDDLE_BAND, $result->unverified);
    }

    public function testAdvancesAreRoundedUpToHundredsAndNoneUpTo30000(): void
    {
        $odd = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(150_001))));
        self::assertSame(3_760_000, $this->of($odd, Authority::IncomeTax, DuePayment::KIND_ADVANCE)[0]->amount->minor(), 'A quarter of 150,001 is 37,500.25, rounded up to 37,600.');

        $none = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(30_000))));
        self::assertSame([], $this->of($none, Authority::IncomeTax, DuePayment::KIND_ADVANCE));

        $middle = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(150_000))));
        self::assertCount(2, $this->of($middle, Authority::IncomeTax, DuePayment::KIND_ADVANCE));
    }

    public function testSettlement2026ElectronicFallsOn3May2027(): void
    {
        $result = $this->compute($this->profile('main', new YearInputsView(2026, chosenMethod: ExpenseMethod::Actual)), income: 900_000, costs: 200_000);

        self::assertSame('2027-05-03', $this->payment($result, 'income_tax.settlement.2026')->dueOn->format('Y-m-d'));
        // The overviews one month after the return, the shortfall 8 days after them.
        self::assertSame('2027-06-11', $this->payment($result, 'social.settlement.2026')->dueOn->format('Y-m-d'));
        self::assertSame('2027-06-11', $this->payment($result, 'health.settlement.2026')->dueOn->format('Y-m-d'));
        self::assertSame('2027-01-01', $this->payment($result, 'social.settlement.2026')->payableFrom->format('Y-m-d'));
        self::assertContains(CzechPayments::UNVERIFIED_ELECTRONIC_DEADLINE, $result->unverified);
        self::assertContains(CzechPayments::UNVERIFIED_OVERVIEW_DEADLINES, $result->unverified);

        $totals = $result->method(ExpenseMethod::Actual)->totals;
        self::assertSame($totals->social->minor() - $this->sum($result, Authority::Social), $this->payment($result, 'social.settlement.2026')->amount->minor(), 'The 5,720 advances to June are credited; the settlement takes the rest.');
        self::assertSame($totals->health->minor() - $this->sum($result, Authority::Health), $this->payment($result, 'health.settlement.2026')->amount->minor());
        self::assertSame($totals->incomeTax->minor(), $this->payment($result, 'income_tax.settlement.2026')->amount->minor());
        self::assertSame(2026, $this->payment($result, 'income_tax.settlement.2026')->taxYear());
    }

    public function testTheSettlementFollowsTheRouteAndTheOverviewFiled(): void
    {
        $paper = $this->compute($this->profile('main', new YearInputsView(2026, chosenMethod: ExpenseMethod::Actual, returnRoute: ReturnRoute::Paper)), income: 900_000, costs: 200_000);
        self::assertSame(['2027-04-01', '2027-05-09'], [$this->payment($paper, 'income_tax.settlement.2026')->dueOn->format('Y-m-d'), $this->payment($paper, 'social.settlement.2026')->dueOn->format('Y-m-d')]);
        self::assertNotContains(CzechPayments::UNVERIFIED_ELECTRONIC_DEADLINE, $paper->unverified);

        $advisor = $this->compute($this->profile('main', new YearInputsView(2026, chosenMethod: ExpenseMethod::Actual, returnRoute: ReturnRoute::Advisor)), income: 900_000, costs: 200_000);
        self::assertSame(['2027-07-01', '2027-08-09'], [$this->payment($advisor, 'income_tax.settlement.2026')->dueOn->format('Y-m-d'), $this->payment($advisor, 'health.settlement.2026')->dueOn->format('Y-m-d')]);

        $filed = $this->profile('main', new YearInputsView(2026, chosenMethod: ExpenseMethod::Actual), new YearInputsView(2027, previousOverviewFiledOn: new \DateTimeImmutable('2027-03-20')));
        $result = $this->compute($filed, income: 900_000, costs: 200_000);
        self::assertSame('2027-03-28', $this->payment($result, 'social.settlement.2026')->dueOn->format('Y-m-d'), 'Eight days after the overview actually filed.');
        self::assertNotContains(CzechPayments::UNVERIFIED_OVERVIEW_DEADLINES, $result->unverified);
    }

    public function testTheSettlementUsesTheChosenMethodAndLessensTheTaxByWhatWasWithheldAndTheBonus(): void
    {
        $inputs = new YearInputsView(2026, [ExpenseMethod::Actual, ExpenseMethod::Lump60], ExpenseMethod::Lump60, employmentTaxWithheld: $this->czk(10_000), lastKnownTax: $this->czk(40_000));
        $result = $this->compute($this->profile('main', $inputs), income: 2_000_000, costs: 100_000);

        $lump = $result->method(ExpenseMethod::Lump60)->totals;
        self::assertSame($lump->social->minor() - $this->sum($result, Authority::Social), $this->payment($result, 'social.settlement.2026')->amount->minor());
        self::assertSame($lump->incomeTax->minor() - $lump->childBonus->minor() - 1_000_000 - 2 * 1_600_000, $this->payment($result, 'income_tax.settlement.2026')->amount->minor());
    }

    public function testAnOverpaymentComesBack(): void
    {
        $inputs = new YearInputsView(2026, previousSocialBase: $this->czk(1_200_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'));
        $result = $this->compute($this->profile('main', $inputs), income: 0, costs: 0);

        $back = $this->payment($result, 'social.overpayment.2026');
        self::assertTrue($back->comesIn());
        self::assertSame($this->sum($result, Authority::Social) - $result->method(ExpenseMethod::Actual)->totals->social->minor(), $back->amount->minor());
        self::assertNull($this->maybe($result, 'social.settlement.2026'));
    }

    public function testTheNewBaseTakesSocialFromTheMonthAfterTheOverviewAndHealthFromIt(): void
    {
        $inputs = new YearInputsView(2026, previousSocialBase: $this->czk(1_200_000), previousHealthBase: $this->czk(600_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'));
        $result = $this->compute($this->profile('main', $inputs));

        // 1,200,000 over twelve months is 100,000 a month, times 29.2 %; 600,000 is 50,000, times 13.5 %.
        self::assertSame([572_000, 2_920_000], [$this->payment($result, 'social.advance.2026-04')->amount->minor(), $this->payment($result, 'social.advance.2026-05')->amount->minor()]);
        self::assertSame([330_600, 675_000], [$this->payment($result, 'health.advance.2026-03')->amount->minor(), $this->payment($result, 'health.advance.2026-04')->amount->minor()]);
    }

    public function testWithoutAFiledDayTheNewBaseFollowsTheOverviewDeadlineOfTheRoute(): void
    {
        // The overviews of 2025, filed electronically, are due on 4 June 2026.
        $inputs = new YearInputsView(2026, previousSocialBase: $this->czk(1_200_000), previousHealthBase: $this->czk(600_000));
        $result = $this->compute($this->profile('main', $inputs));

        self::assertSame([572_000, 2_920_000], [$this->payment($result, 'social.advance.2026-06')->amount->minor(), $this->payment($result, 'social.advance.2026-07')->amount->minor()]);
        self::assertSame([330_600, 675_000], [$this->payment($result, 'health.advance.2026-05')->amount->minor(), $this->payment($result, 'health.advance.2026-06')->amount->minor()]);
    }

    public function testTheBaseIsSpreadOverTheMonthsOfActivityOfItsYear(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2025-07-01')),
        ], [2026 => new YearInputsView(2026, previousSocialBase: $this->czk(600_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'))], []);

        $result = $this->compute($profile);

        self::assertSame(2_920_000, $this->payment($result, 'social.advance.2026-05')->amount->minor(), '600,000 over the six months of 2025 with activity.');
    }

    public function testAFiveMonthPreviousYearDividesTheBasesByFive(): void
    {
        $inputs = new YearInputsView(2026, previousSocialBase: $this->czk(1_000_000), previousHealthBase: $this->czk(300_001), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'), previousActivityMonths: 5);
        $result = $this->compute($this->profile('main', $inputs));

        // 300,001 / 5 is 60,000.20, up to 60,001; times 13.5 % is 8,100.135, up to 8,101.
        self::assertSame(810_100, $this->payment($result, 'health.advance.2026-04')->amount->minor());
        // 1,000,000 / 5 is 200,000; times 29.2 % is 58,400.
        self::assertSame(5_840_000, $this->payment($result, 'social.advance.2026-05')->amount->minor());
        self::assertNotContains(CzechPayments::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS, $result->unverified);
    }

    public function testTheEnteredMonthsOfActivityWinOverTheStatuses(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2025-07-01')),
        ], [2026 => new YearInputsView(2026, previousHealthBase: $this->czk(300_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'), previousActivityMonths: 5)], []);

        // 300,000 / 5 is 60,000, times 13.5 %; the six months of the statuses would give 6,750.
        self::assertSame(810_000, $this->payment($this->compute($profile), 'health.advance.2026-04')->amount->minor());
    }

    public function testWithoutMonthsOrStatusesOfThePreviousYearTheBaseIsSpreadOverTwelveFlagged(): void
    {
        $inputs = new YearInputsView(2026, previousHealthBase: $this->czk(600_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'));
        $result = $this->compute($this->profile('main', $inputs));

        self::assertSame(675_000, $this->payment($result, 'health.advance.2026-04')->amount->minor());
        self::assertSame(1, array_count_values($result->unverified)[CzechPayments::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS] ?? 0);
        self::assertNotContains(CzechPayments::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS, $this->compute($this->profile('main'))->unverified, 'No base, nothing divided.');
    }

    public function testTheStatusesOfThePreviousYearAreNotFlagged(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2025-07-01')),
        ], [2026 => new YearInputsView(2026, previousSocialBase: $this->czk(600_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'))], []);

        self::assertNotContains(CzechPayments::UNVERIFIED_PREVIOUS_ACTIVITY_MONTHS, $this->compute($profile)->unverified);
    }

    public function testASecondaryActivityPaysSocialOnlyWithABaseFromTheYearBefore(): void
    {
        $without = $this->compute($this->profile('secondary'));
        self::assertSame([], $this->of($without, Authority::Social, DuePayment::KIND_ADVANCE));
        self::assertCount(12, $this->of($without, Authority::Health, DuePayment::KIND_ADVANCE), 'Health knows no secondary activity.');

        $with = $this->compute($this->profile('secondary', new YearInputsView(2026, previousSocialBase: $this->czk(120_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'))));
        self::assertNull($this->maybe($with, 'social.advance.2026-04'));
        self::assertSame(292_000, $this->payment($with, 'social.advance.2026-05')->amount->minor());
    }

    public function testAMonthWithoutActivityOrWithoutTheHealthMinimumPaysNoAdvance(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'none', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('b', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-12-01')),
            new StatusPeriod('c', StatusKind::HealthMinimumExempt, 'yes', new \DateTimeImmutable('2026-01-01')),
        ], [], []);

        $result = $this->compute($profile);

        self::assertSame(['social.advance.2026-12'], array_map(static fn (DuePayment $payment): string => $payment->key(), $this->of($result, null, DuePayment::KIND_ADVANCE)));
    }

    public function testSicknessAdvanceIsDueOnTheLastDayOfItsMonth(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
            new StatusPeriod('b', StatusKind::SicknessInsurance, 'on', new \DateTimeImmutable('2026-11-01'), $this->czk(10_000)),
        ], [], []);

        $result = $this->compute($profile);

        self::assertSame([['2026-11-30', 27_000], ['2026-12-31', 27_000]], array_map(static fn (DuePayment $payment): array => [$payment->dueOn->format('Y-m-d'), $payment->amount->minor()], $this->of($result, Authority::Sickness, DuePayment::KIND_ADVANCE)));
    }

    public function testToDateHasEveryAdvanceOfTheYearButNoSettlement(): void
    {
        $today = new \DateTimeImmutable('2026-11-20', new \DateTimeZone(self::ZONE));
        $result = new CzechSoleTrader()->compute(new RegimeInput(2026, $this->profile('main'), $this->amounts(900_000, 200_000), $today, $today));

        self::assertCount(24, $this->of($result, null, DuePayment::KIND_ADVANCE));
        self::assertSame([], $this->of($result, null, DuePayment::KIND_SETTLEMENT));
        self::assertNotContains(CzechPayments::UNVERIFIED_OVERVIEW_DEADLINES, $result->unverified);
    }

    public function testThePaymentsAreByDueDay(): void
    {
        $result = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(200_000))), income: 900_000, costs: 200_000);

        $days = array_map(static fn (DuePayment $payment): string => $payment->dueOn->format('Y-m-d').' '.$payment->authority->value, $result->payments);
        $sorted = $days;
        sort($sorted);

        self::assertSame($sorted, $days);
    }

    public function testTheDeadlinesMoveToTheNextWorkingDay(): void
    {
        $zone = new \DateTimeZone(self::ZONE);

        self::assertSame('2026-05-04', CzechDeadlines::returnDeadline(2025, ReturnRoute::Electronic, $zone)->format('Y-m-d'));
        self::assertSame('2027-05-03', CzechDeadlines::returnDeadline(2026, ReturnRoute::Electronic, $zone)->format('Y-m-d'));
        self::assertSame('2027-04-01', CzechDeadlines::returnDeadline(2026, ReturnRoute::Paper, $zone)->format('Y-m-d'));
        self::assertSame('2024-04-02', CzechDeadlines::returnDeadline(2023, ReturnRoute::Paper, $zone)->format('Y-m-d'), '1 April 2024 is Easter Monday.');
        self::assertSame('2028-07-03', CzechDeadlines::returnDeadline(2027, ReturnRoute::Advisor, $zone)->format('Y-m-d'));
        self::assertSame('2027-06-03', CzechDeadlines::overviewDeadline(2026, ReturnRoute::Electronic, $zone)->format('Y-m-d'));
        self::assertTrue(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-04-03', $zone)), 'Good Friday');
        self::assertTrue(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-04-06', $zone)), 'Easter Monday');
        self::assertFalse(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-04-07', $zone)));
        self::assertFalse(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-04-02', $zone)));
        self::assertTrue(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-12-24', $zone)));
        self::assertFalse(CzechDeadlines::isDayOff(new \DateTimeImmutable('2026-12-23', $zone)));
        self::assertSame('2027-12-27', CzechDeadlines::workingDayFrom(new \DateTimeImmutable('2027-12-24', $zone))->format('Y-m-d'));
        self::assertSame('2025-04-22', CzechDeadlines::workingDayFrom(new \DateTimeImmutable('2025-04-18', $zone))->format('Y-m-d'), 'Good Friday, the weekend and Easter Monday of 2025.');
    }

    public function testBeforeTheOverviewTheAdvancesTakeTheBaseOfTheYearBefore(): void
    {
        $profile = new ProfileView([new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2024-01-01'))], [
            2025 => new YearInputsView(2025, previousSocialBase: $this->czk(600_000), previousHealthBase: $this->czk(600_000)),
        ], []);

        $result = $this->compute($profile);

        // 600,000 over the twelve months of 2024: 50,000 a month, times 29.2 % and 13.5 %.
        self::assertSame([1_460_000, 675_000], [$this->payment($result, 'social.advance.2026-01')->amount->minor(), $this->payment($result, 'health.advance.2026-01')->amount->minor()]);
        self::assertSame([1_460_000, 330_600], [$this->payment($result, 'social.advance.2026-06')->amount->minor(), $this->payment($result, 'health.advance.2026-06')->amount->minor()], 'From the overview of 2025, which names no base, the minimum.');
        self::assertSame(500_500, $this->payment($result, 'social.advance.2026-07')->amount->minor(), 'Social from the month after the overview.');
    }

    public function testABaseOfAYearWithOneMonthOfActivityIsThatMonthsBase(): void
    {
        $profile = new ProfileView([
            new StatusPeriod('a', StatusKind::Activity, 'main', new \DateTimeImmutable('2025-01-01')),
            new StatusPeriod('b', StatusKind::Activity, 'none', new \DateTimeImmutable('2025-02-01')),
            new StatusPeriod('c', StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')),
        ], [2026 => new YearInputsView(2026, previousSocialBase: $this->czk(120_000), previousOverviewFiledOn: new \DateTimeImmutable('2026-04-20'))], []);

        $result = $this->compute($profile);

        self::assertSame(3_504_000, $this->payment($result, 'social.advance.2026-05')->amount->minor(), 'The 120,000 of the one month of 2025, times 29.2 %.');
    }

    public function testTheChildBonusComesBackAndNoRestMeansNoPayment(): void
    {
        $child = new ChildInput('Child', new \DateTimeImmutable('2020-05-01'), 1, false, 12);
        $result = $this->compute($this->profile('main', new YearInputsView(2026, [ExpenseMethod::Actual], ExpenseMethod::Actual, [$child])), income: 300_000, costs: 200_000);

        $totals = $result->method(ExpenseMethod::Actual)->totals;
        self::assertGreaterThan(0, $totals->childBonus->minor());
        self::assertSame($totals->childBonus->minor() - $totals->incomeTax->minor(), $this->payment($result, 'income_tax.overpayment.2026')->amount->minor());

        $none = $this->compute($this->profile('main'));
        self::assertNull($this->maybe($none, 'income_tax.settlement.2026'), 'No tax and no advance: nothing to pay or to get back.');
        self::assertNull($this->maybe($none, 'income_tax.overpayment.2026'));
    }

    public function testTheTaxAdvanceRulesAreListedAsUsed(): void
    {
        $result = $this->compute($this->profile('main', new YearInputsView(2026, lastKnownTax: $this->czk(200_000))));

        $keys = array_map(static fn (RuleRef $rule): string => $rule->key, $result->rulesUsed);
        foreach (['tax.advance.none_up_to', 'tax.advance.two_up_to', 'tax.advance.four_share', 'social.rate', 'health.rate'] as $key) {
            self::assertContains($key, $keys);
        }
    }

    public function testACutOffBeforeTheLastDayHasNoSettlement(): void
    {
        $today = new \DateTimeImmutable('2026-11-20', new \DateTimeZone(self::ZONE));
        $result = new CzechSoleTrader()->compute(new RegimeInput(2026, $this->profile('main'), $this->amounts(900_000, 200_000), $today, $today->setDate(2026, 12, 30)));

        self::assertSame([], $this->of($result, null, DuePayment::KIND_SETTLEMENT));
        self::assertSame([], $this->of($result, null, DuePayment::KIND_OVERPAYMENT));
    }

    public function testNoAmountsAreZero(): void
    {
        $none = AmountsView::none(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'));

        foreach (AmountKind::cases() as $kind) {
            $totals = $none->totalOf($kind);
            self::assertSame([0, 0, 0, 0], [$totals->gross->minor(), $totals->net->minor(), $totals->vat->minor(), $totals->counted->minor()]);
        }
        self::assertSame([[], [], [], []], [$none->sums, $none->lines, $none->unavailableSources, $none->unverified]);
    }

    public function testEasterMovesItsFridayAndMondayEveryYear(): void
    {
        $zone = new \DateTimeZone(self::ZONE);
        $sundays = ['1818-03-22', '1886-04-25', '1943-04-25', '1954-04-18', '1961-04-02', '2000-04-23', '2002-03-31', '2008-03-23', '2011-04-24', '2013-03-31', '2016-03-27', '2018-04-01', '2019-04-21', '2024-03-31', '2025-04-20', '2027-03-28', '2029-04-01', '2038-04-25', '2285-03-22'];
        foreach ($sundays as $sunday) {
            $day = new \DateTimeImmutable($sunday, $zone);
            self::assertTrue(CzechDeadlines::isDayOff($day->modify('-2 days')), 'Good Friday before '.$sunday);
            self::assertTrue(CzechDeadlines::isDayOff($day->modify('+1 day')), 'Easter Monday after '.$sunday);
            self::assertFalse(CzechDeadlines::isDayOff($day->modify('-3 days')), 'Thursday before '.$sunday);
            self::assertFalse(CzechDeadlines::isDayOff($day->modify('+2 days')), 'Tuesday after '.$sunday);
        }
    }

    public function testThePaidMethodIsTheChosenThenTheCheaperThenTheFirst(): void
    {
        $result = $this->compute($this->profile('main'), income: 900_000, costs: 200_000);
        [$actual, $lump] = [$result->method(ExpenseMethod::Actual), $result->method(ExpenseMethod::Lump60)];

        self::assertSame($lump, CzechPayments::paidMethod($result->methods, ExpenseMethod::Lump60, ExpenseMethod::Actual));
        self::assertSame($actual, CzechPayments::paidMethod($result->methods, null, ExpenseMethod::Actual));
        self::assertSame($actual, CzechPayments::paidMethod($result->methods, ExpenseMethod::Lump80, null));
        self::assertSame($lump, CzechPayments::paidMethod($result->methods, ExpenseMethod::Lump80, ExpenseMethod::Lump60));
    }

    private function compute(ProfileView $profile, int $income = 0, int $costs = 0): RegimeResult
    {
        $today = new \DateTimeImmutable('2026-11-20', new \DateTimeZone(self::ZONE));

        return new CzechSoleTrader()->compute(new RegimeInput(2026, $profile, $this->amounts($income, $costs), $today, $today->setDate(2026, 12, 31)));
    }

    private function profile(string $activity, ?YearInputsView $inputs = null, ?YearInputsView $next = null): ProfileView
    {
        $years = [];
        foreach ([$inputs, $next] as $year) {
            if ($year instanceof YearInputsView) {
                $years[$year->year] = $year;
            }
        }

        return new ProfileView([new StatusPeriod('a', StatusKind::Activity, $activity, new \DateTimeImmutable('2026-01-01'))], $years, []);
    }

    private function amounts(int $income, int $costs): AmountsView
    {
        $totals = fn (int $czk): AmountTotals => new AmountTotals($this->czk($czk), $this->czk($czk), $this->czk(0), $this->czk($czk));

        return new AmountsView(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), [], [AmountKind::Income->value => $totals($income), AmountKind::Cost->value => $totals($costs)], [], [], [], [], [], []);
    }

    private function czk(int $czk): Money
    {
        return Money::ofMinor($czk * 100, Currency::of('CZK'));
    }

    private function payment(RegimeResult $result, string $key): DuePayment
    {
        return $this->maybe($result, $key) ?? self::fail('No payment '.$key);
    }

    private function maybe(RegimeResult $result, string $key): ?DuePayment
    {
        foreach ($result->payments as $payment) {
            if ($key === $payment->key()) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @return array{string, int}
     */
    private function dueAndAmount(RegimeResult $result, string $key): array
    {
        $payment = $this->payment($result, $key);

        return [$payment->dueOn->format('Y-m-d'), $payment->amount->minor()];
    }

    /**
     * @return list<DuePayment>
     */
    private function of(RegimeResult $result, ?Authority $authority, string $kind): array
    {
        return array_values(array_filter($result->payments, static fn (DuePayment $payment): bool => (!$authority instanceof Authority || $authority === $payment->authority) && $kind === $payment->kind));
    }

    private function sum(RegimeResult $result, Authority $authority): int
    {
        return array_sum(array_map(static fn (DuePayment $payment): int => $payment->amount->minor(), $this->of($result, $authority, DuePayment::KIND_ADVANCE)));
    }
}
