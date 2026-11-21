<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechDeadlines;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechRuleBook;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSwitchAdjustment;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\OpenAmountsView;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\SwitchAdjustment;
use Foresi\TaxesAndContributions\Contract\YearBefore;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * The adjustment of a change of the expense method (ZDP § 23 (8), old T10):
 * the receivables open at the end of the year before, less its open payables
 * with actual costs, raise that year's base, and the tax on it at the
 * marginal rate is due by the return deadline of the year of the change, as
 * a warning and a payment apart from the totals.
 */
final class CzechSwitchAdjustmentTest extends TestCase
{
    public function testFromActualCostsToALumpSumTheOpenReceivablesLessPayablesRaiseTheYearBefore(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]);

        $result = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 24_200));
        $switch = $result->switchAdjustment;

        self::assertInstanceOf(SwitchAdjustment::class, $switch);
        self::assertSame([2026, ExpenseMethod::Actual, 'lump_60', '2026-12-31'], [$switch->adjustedYear, $switch->from, $switch->into, $switch->openOn->format('Y-m-d')]);
        self::assertSame([121_000, 24_200, 96_800, 400_000], [$this->czk($switch->receivables), $this->czk($switch->payables), $this->czk($switch->baseChange), $this->czk($switch->previousTaxBase)]);
        self::assertSame(['15', 14_520], [$switch->rate, $this->czk($switch->tax)], '15 % of 96,800: the base of 400,000 stays below the higher band.');
        $due = CzechDeadlines::returnDeadline(2027, ReturnRoute::Electronic, new \DateTimeZone('Europe/Prague'));
        self::assertSame($due->format('Y-m-d'), $switch->dueOn->format('Y-m-d'));
        self::assertSame(CzechSwitchAdjustment::SOURCE, $switch->sourceUrl);
        self::assertContains(CzechSwitchAdjustment::WARNING, $result->warnings);
        self::assertContains(CzechSwitchAdjustment::UNVERIFIED, $result->unverified);
        self::assertContains(CzechSwitchAdjustment::UNVERIFIED_INSURANCE, $result->unverified);
        self::assertNotContains(CzechSwitchAdjustment::UNVERIFIED_PREVIOUS_BASE, $result->unverified);
        self::assertContains(CzechSwitchAdjustment::GAP_INVENTORY_AND_RESERVES, $result->knownGaps);

        $payment = $this->paymentOf($result);
        self::assertSame([Authority::IncomeTax, DuePayment::KIND_SWITCH_ADJUSTMENT, '2027', '2027-01-01', $due->format('Y-m-d'), 14_520], [$payment->authority, $payment->kind, $payment->period, $payment->payableFrom->format('Y-m-d'), $payment->dueOn->format('Y-m-d'), $this->czk($payment->amount)]);
        self::assertSame('income_tax.switch_adjustment.2027', $payment->key());

        $without = $this->compute(2027, $profile, null);
        self::assertNull($without->switchAdjustment);
        self::assertEquals($without->methods, $result->methods, 'The adjustment is never inside the totals of the year.');
        self::assertCount(\count($without->payments) + 1, $result->payments);
    }

    public function testTheTaxAtTheMarginalRateCrossesIntoTheHigherBand(): void
    {
        $rules = new CzechRuleBook();
        $first = new \DateTimeImmutable('2026-01-01');
        $threshold = $rules->valueOn('average_wage', $first)->minor() * 36;
        $base = Czk::downToHundreds($threshold) - 5_000_000;
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]);

        $switch = $this->compute(2027, $profile, $this->before(2026, intdiv($base, 100), 0, 100_000, 0))->switchAdjustment;

        $taxOf = static fn (int $of): int => Czk::upToCzk(Czk::percent(min($of, $threshold), '15') + Czk::percent(max(0, $of - $threshold), '23'));
        $expected = $taxOf($base + 10_000_000) - $taxOf($base);
        self::assertSame([$base, '23'], [$switch?->previousTaxBase?->minor(), $switch?->rate]);
        self::assertSame($expected, $switch?->tax->minor());
        self::assertGreaterThan(1_500_000, $expected, 'Part of the change is taxed at 23 %.');
    }

    public function testIntoAWholeYearOfTheFlatRateFromActualCosts(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual)], [new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-01-01'))]);

        $switch = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 24_200))->switchAdjustment;

        self::assertSame([SwitchAdjustment::INTO_FLAT_RATE, 96_800, 14_520], [$switch?->into, $this->czk($switch?->baseChange), $this->czk($switch?->tax)]);
    }

    public function testFromALumpSumIntoTheFlatRateOnlyTheReceivablesCount(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Lump60)], [new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-01-01'))]);

        $switch = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 24_200))->switchAdjustment;

        self::assertSame([ExpenseMethod::Lump60, 0, 121_000, 200_000, 18_150], [$switch?->from, $this->czk($switch?->payables), $this->czk($switch?->baseChange), $this->czk($switch?->previousTaxBase), $this->czk($switch?->tax)]);
    }

    public function testNoChangeOfMethodAddsNothing(): void
    {
        $before = $this->before(2026, 500_000, 100_000, 121_000, 24_200);
        $band = [new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2026-12-01'))];
        $cases = [
            'actual both years' => $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Actual)]),
            'a lump sum both years' => $this->profile([2026 => $this->filed(ExpenseMethod::Lump60), 2027 => $this->filed(ExpenseMethod::Lump40)]),
            'from a lump sum to actual costs' => $this->profile([2026 => $this->filed(ExpenseMethod::Lump60), 2027 => $this->filed(ExpenseMethod::Actual)]),
            'this year still open' => $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => new YearInputsView(2027)]),
            'the year before still open' => $this->profile([2026 => new YearInputsView(2026), 2027 => $this->filed(ExpenseMethod::Lump60)]),
            'the flat rate already the year before' => $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)], $band),
        ];
        foreach ($cases as $case => $profile) {
            $result = $this->compute(2027, $profile, $before);
            self::assertNull($result->switchAdjustment, $case);
            self::assertNotContains(CzechSwitchAdjustment::WARNING, $result->warnings, $case);
            self::assertNotContains(CzechSwitchAdjustment::UNVERIFIED, $result->unverified, $case);
            self::assertSame([], array_filter($result->payments, static fn (DuePayment $payment): bool => DuePayment::KIND_SWITCH_ADJUSTMENT === $payment->kind), $case);
        }
        $other = new YearBefore(2025, $before->amounts, $before->open);
        self::assertNull($this->compute(2027, $this->profile([2025 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]), $other)->switchAdjustment, 'Only the year right before is adjusted.');
    }

    public function testAFlatRateOnlyFromDecemberIsNoWholeYearInIt(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => new YearInputsView(2027)], [new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-12-01'))]);

        self::assertNull($this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 0))->switchAdjustment);
    }

    public function testAYearBeforeWithoutRulesTakesTheBasicRateOfTheYearOfTheChange(): void
    {
        $profile = $this->profile([2025 => $this->filed(ExpenseMethod::Actual), 2026 => $this->filed(ExpenseMethod::Lump60)], [], 2025);

        $result = $this->compute(2026, $profile, $this->before(2025, 500_000, 100_000, 121_000, 24_200));

        self::assertSame([null, '15', 14_520], [$result->switchAdjustment?->previousTaxBase, $result->switchAdjustment?->rate, $this->czk($result->switchAdjustment?->tax)]);
        self::assertContains(CzechSwitchAdjustment::UNVERIFIED_PREVIOUS_BASE, $result->unverified);
        self::assertSame(CzechDeadlines::returnDeadline(2026, ReturnRoute::Electronic, new \DateTimeZone('Europe/Prague'))->format('Y-m-d'), $result->switchAdjustment?->dueOn->format('Y-m-d'));
    }

    public function testPayablesAboveTheReceivablesLowerTheTaxWithNoPayment(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]);

        $result = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 10_000, 30_000));

        self::assertSame([-20_000, -3_000], [$this->czk($result->switchAdjustment?->baseChange), $this->czk($result->switchAdjustment?->tax)]);
        self::assertContains(CzechSwitchAdjustment::WARNING, $result->warnings, 'The warning names the change all the same.');
        self::assertSame([], array_filter($result->payments, static fn (DuePayment $payment): bool => DuePayment::KIND_SWITCH_ADJUSTMENT === $payment->kind));
    }

    public function testNothingOpenAddsNoPayment(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]);

        $result = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 0, 0));

        self::assertSame(0, $result->switchAdjustment?->tax->minor());
        self::assertSame([], array_filter($result->payments, static fn (DuePayment $payment): bool => DuePayment::KIND_SWITCH_ADJUSTMENT === $payment->kind));
    }

    public function testTheOnlyMethodComparedIsTheOneFiled(): void
    {
        $profile = $this->profile([2026 => new YearInputsView(2026, [ExpenseMethod::Actual]), 2027 => new YearInputsView(2027, [ExpenseMethod::Lump40])]);

        $switch = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 24_200))->switchAdjustment;

        self::assertSame([ExpenseMethod::Actual, 'lump_40'], [$switch?->from, $switch?->into]);
    }

    public function testAtTheYearEndThePaymentIsInOrderOfItsDueDay(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => $this->filed(ExpenseMethod::Lump60)]);

        $payments = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 24_200), '2027-12-31', 1_000_000)->payments;

        $days = array_map(static fn (DuePayment $payment): string => $payment->dueOn->format('Y-m-d').' '.$payment->authority->value, $payments);
        $sorted = $days;
        sort($sorted);
        self::assertSame($sorted, $days);
        $keys = array_map(static fn (DuePayment $payment): string => $payment->key(), $payments);
        self::assertGreaterThan(array_search('income_tax.switch_adjustment.2027', $keys, true), array_search('social.settlement.2027', $keys, true), 'The overview settles after the return.');
    }

    public function testAFlatRateYearWithAnOrdinaryDecemberIsNoWholeYearInIt(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => new YearInputsView(2027)], [
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-01-01')),
            new StatusPeriod('out', StatusKind::FlatRateBand, 'none', new \DateTimeImmutable('2027-12-01')),
        ]);

        self::assertNull($this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 0))->switchAdjustment);
    }

    public function testMonthsWithoutActivityDoNotBreakAWholeYearInTheFlatRate(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => new YearInputsView(2027)], [
            new StatusPeriod('pause', StatusKind::Activity, 'none', new \DateTimeImmutable('2027-01-01')),
            new StatusPeriod('back', StatusKind::Activity, 'main', new \DateTimeImmutable('2027-03-01')),
            new StatusPeriod('band', StatusKind::FlatRateBand, '1', new \DateTimeImmutable('2027-03-01')),
        ]);

        self::assertSame(SwitchAdjustment::INTO_FLAT_RATE, $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 0))->switchAdjustment?->into);
    }

    public function testThePaperRouteMovesTheDeadline(): void
    {
        $profile = $this->profile([2026 => $this->filed(ExpenseMethod::Actual), 2027 => new YearInputsView(2027, [ExpenseMethod::Lump60], ExpenseMethod::Lump60, returnRoute: ReturnRoute::Paper)]);

        $switch = $this->compute(2027, $profile, $this->before(2026, 500_000, 100_000, 121_000, 0))->switchAdjustment;

        self::assertSame(CzechDeadlines::returnDeadline(2027, ReturnRoute::Paper, new \DateTimeZone('Europe/Prague'))->format('Y-m-d'), $switch?->dueOn->format('Y-m-d'));
    }

    private function compute(int $year, ProfileView $profile, ?YearBefore $before, ?string $cutOff = null, int $income = 0): RegimeResult
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $today = new \DateTimeImmutable(\sprintf('%04d-03-10', $year), $zone);

        return new CzechSoleTrader()->compute(new RegimeInput($year, $profile, $this->amounts($year, $income, 0), $today, null === $cutOff ? $today : new \DateTimeImmutable($cutOff, $zone), $before));
    }

    /**
     * @param array<int, YearInputsView> $years
     * @param list<StatusPeriod>         $more
     */
    private function profile(array $years, array $more = [], int $from = 2026): ProfileView
    {
        return new ProfileView([new StatusPeriod('main', StatusKind::Activity, 'main', new \DateTimeImmutable(\sprintf('%04d-01-01', $from))), ...$more], $years, []);
    }

    private function filed(ExpenseMethod $method): YearInputsView
    {
        return new YearInputsView(2026, [$method], $method);
    }

    private function before(int $year, int $income, int $costs, int $receivables, int $payables): YearBefore
    {
        $money = static fn (int $czk): Money => Money::ofMinor($czk * 100, Currency::CZK);
        $totals = static fn (int $czk): AmountTotals => new AmountTotals($money($czk), $money($czk), $money(0), $money($czk));

        return new YearBefore(
            $year,
            $this->amounts($year, $income, $costs),
            new OpenAmountsView(new \DateTimeImmutable(\sprintf('%04d-12-31', $year), new \DateTimeZone('Europe/Prague')), [AmountKind::Income->value => $totals($receivables), AmountKind::Cost->value => $totals($payables)], [], [], []),
        );
    }

    private function amounts(int $year, int $income, int $costs): AmountsView
    {
        $money = static fn (int $czk): Money => Money::ofMinor($czk * 100, Currency::CZK);
        $totals = static fn (int $czk): AmountTotals => new AmountTotals($money($czk), $money($czk), $money(0), $money($czk));

        return new AmountsView(new \DateTimeImmutable(\sprintf('%04d-01-01', $year)), new \DateTimeImmutable(\sprintf('%04d-12-31', $year)), [], [AmountKind::Income->value => $totals($income), AmountKind::Cost->value => $totals($costs)], [], [], [], [], [], []);
    }

    private function paymentOf(RegimeResult $result): DuePayment
    {
        foreach ($result->payments as $payment) {
            if (DuePayment::KIND_SWITCH_ADJUSTMENT === $payment->kind) {
                return $payment;
            }
        }

        self::fail('No adjustment payment.');
    }

    private function czk(?Money $money): ?int
    {
        return $money instanceof Money ? intdiv($money->minor(), 100) : null;
    }
}
