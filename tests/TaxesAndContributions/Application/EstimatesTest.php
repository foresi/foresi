<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Application\YearEndForecast;
use Foresi\TaxesAndContributions\Contract\AccrualMonth;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ForecastAmount;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Foresi\Tests\TaxesAndContributions\Support\FakeAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\FakeForecastSource;
use Foresi\Tests\TaxesAndContributions\Support\RecordingLogger;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The estimate (Goals 1-3): "to date" is the regime of the owner's legal form
 * over the actual amounts and the months up to today; "year end" adds the
 * forecast to 31 December over every month, kept apart and split by
 * certainty, with its levies spread over the months (old T6).
 */
final class EstimatesTest extends TaxesTestCase
{
    public function testYearEndIsToDatePlusForecastAndShowsBoth(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-01-01'));
        $forecast = new FakeForecastSource([$this->expected('a', '2026-12-15', 605_000)]);
        $invoices = new FakeAmountSource('invoicing.payments', [$this->amount('a', '2026-11-10', 605_000, 500_000)], shares: ['a' => new VatShare(1_000_000, 1_210_000)]);

        $estimate = $this->estimates([$invoices, new FakeAmountSource('purchasing.payments', [])], [$forecast])->of(2026);

        self::assertInstanceOf(RegimeResult::class, $estimate->toDate);
        self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
        self::assertNull($estimate->yearEndReason);
        self::assertSame([['2026-11-20', '2026-12-31']], $forecast->asked, 'The forecast runs from today: an open amount is not paid yet, so nothing counts twice.');
        self::assertSame(500_000, $estimate->toDate->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertSame(1_000_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->income->minor(), 'Year end is to date plus the forecast.');
        self::assertCount(11, $estimate->toDate->months);
        self::assertCount(12, $estimate->yearEnd->months, 'The year end counts every month.');
        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([605_000, 500_000, 105_000, 500_000], $this->figures($estimate->forecast->totalOf(AmountKind::Income)));
        self::assertSame([605_000, 500_000, 105_000, 500_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Document, AmountKind::Income)));
        self::assertSame([0, 0, 0, 0], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Plan, AmountKind::Income)));
        self::assertSame([], $estimate->forecast->flags);
        self::assertSame(['2026-12', AmountKind::Income, 'invoicing.invoices', 500_000], [$estimate->forecast->sums[0]->month, $estimate->forecast->sums[0]->kind, $estimate->forecast->sums[0]->source, $estimate->forecast->sums[0]->counted->minor()]);
        self::assertSame(500_000, $estimate->amounts?->totalOf(AmountKind::Income)->counted->minor(), 'The amounts stay those to date.');
        self::assertSame([['a']], $invoices->sharesAsked);
    }

    public function testAPlannedIncomeCountsAsStatedAndIsFlagged(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-01-01'));
        $invoices = new FakeAmountSource('invoicing.payments', [], shares: ['p' => new VatShare(1_000_000, 1_210_000)]);
        $forecast = new FakeForecastSource([
            $this->expected('p', '2026-12-01', 1_000_000, ForecastCertainty::Plan, source: 'cash-flow.planned_items'),
            $this->expected('r', '2026-12-10', 121_000, ForecastCertainty::Schedule, source: 'invoicing.recurring_invoices'),
            $this->expected('c', '2026-12-10', 50_000, ForecastCertainty::Plan, AmountKind::Cost, source: 'cash-flow.planned_items'),
            $this->expected('q', '2026-12-20', 20_000, ForecastCertainty::Plan, source: 'cash-flow.planned_items'),
            $this->expected('o', '2026-11-25', 30_000, ForecastCertainty::Plan, source: 'cash-flow.planned_items'),
        ]);

        $estimate = $this->estimates([$invoices], [$forecast])->of(2026);

        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([1_050_000, 1_050_000, 0, 1_050_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Plan, AmountKind::Income)), 'A plan counts as stated, with no VAT split, also for a VAT payer.');
        self::assertSame([], $estimate->forecast->unverified, 'Nothing was split, so the cash basis of VAT plays no part.');
        self::assertSame([
            ['2026-11', 'income', 'cash-flow.planned_items', 30_000],
            ['2026-12', 'cost', 'cash-flow.planned_items', 50_000],
            ['2026-12', 'income', 'cash-flow.planned_items', 1_020_000],
            ['2026-12', 'income', 'invoicing.recurring_invoices', 121_000],
        ], array_map(static fn (AmountSum $sum): array => [$sum->month, $sum->kind->value, $sum->source, $sum->counted->minor()], $estimate->forecast->sums), 'By month, kind and source, each summed once.');
        self::assertSame([121_000, 121_000, 0, 121_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Schedule, AmountKind::Income)));
        self::assertSame([50_000, 50_000, 0, 50_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Plan, AmountKind::Cost)));
        self::assertSame([ForecastView::FLAG_PLAN_NO_VAT_SPLIT], $estimate->forecast->flags);
        self::assertSame([], $invoices->sharesAsked, 'No document, no ratio asked.');
        self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
        self::assertSame(1_171_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertSame(50_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->costs->minor());
    }

    public function testADocumentWithoutARatioOrWithoutItsRecordsCountsGrossAndARefundSplitsLikeAPayment(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-12-01'));
        $invoices = new FakeAmountSource('invoicing.payments', [], shares: ['n' => new VatShare(1_000_000, 1_210_000), 'b' => new VatShare(1_000_000, 1_210_000)]);
        $forecast = new FakeForecastSource([
            $this->expected('u', '2026-12-05', 121_000),
            $this->expected('u', '2026-12-06', 121_000),
            $this->expected('x', '2026-12-05', 242_000, restricted: true),
            $this->expected('n', '2026-12-05', -121_000),
            $this->expected('b', '2026-11-30', 121_000, kind: AmountKind::Cost),
        ]);

        $estimate = $this->estimates([$invoices], [$forecast])->of(2026);

        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([363_000, 384_000, -21_000, 384_000], $this->figures($estimate->forecast->totalOf(AmountKind::Income)), 'Unknown and restricted count gross; the refund is -1,000 net.');
        self::assertSame([121_000, 100_000, 21_000, 121_000], $this->figures($estimate->forecast->totalOf(AmountKind::Cost)), 'Before the VAT-payer day a document counts gross.');
        self::assertSame([ForecastView::FLAG_NO_VAT_SPLIT_UNKNOWN, ForecastView::FLAG_NO_VAT_SPLIT_RESTRICTED], $estimate->forecast->flags);
        self::assertSame([AmountsView::UNVERIFIED_VAT_CASH_BASIS], $estimate->forecast->unverified);
        self::assertContains(AmountsView::UNVERIFIED_VAT_CASH_BASIS, $estimate->unverified);
        self::assertSame([['u', 'n', 'b']], $invoices->sharesAsked);
    }

    public function testWithoutCashFlowYearEndIsNullWithReason(): void
    {
        $estimate = $this->estimates([new FakeAmountSource('invoicing.payments', []), new FakeAmountSource('purchasing.payments', [])])->of(2026);

        self::assertInstanceOf(RegimeResult::class, $estimate->toDate);
        self::assertNull($estimate->yearEnd);
        self::assertSame(EstimateView::YEAR_END_CASH_FLOW_NOT_INSTALLED, $estimate->yearEndReason);
        self::assertNull($estimate->forecast);
        self::assertSame([], $estimate->accruals);
    }

    public function testTheContainerReadsTheForecastOnlyWithCashFlowInstalled(): void
    {
        $estimate = self::getContainer()->get(Estimates::class)->of(2026);

        // In the module subset without cash-flow (bin/module-subset), its integration code is not loaded at all.
        if (interface_exists(ForecastReader::class)) {
            self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
            self::assertNull($estimate->yearEndReason);
        } else {
            self::assertNull($estimate->yearEnd);
            self::assertSame(EstimateView::YEAR_END_CASH_FLOW_NOT_INSTALLED, $estimate->yearEndReason);
        }
    }

    public function testARefusedOrIncompleteForecastSaysSo(): void
    {
        $logger = new RecordingLogger();
        $container = self::getContainer();
        $refused = new Estimates($container->get(ProfileReads::class), $this->amounts([]), [new CzechSoleTrader()], $container->get(InstanceClock::class), [new FakeForecastSource(refuses: true)], new YearEndForecast([]), $logger)->of(2026);
        self::assertSame('warning', $logger->records[0][0] ?? null);
        self::assertInstanceOf(\DomainException::class, $logger->records[0][1]['exception'] ?? null);
        self::assertNull($refused->yearEnd);
        self::assertSame(EstimateView::YEAR_END_FORECAST_UNAVAILABLE, $refused->yearEndReason);
        self::assertInstanceOf(RegimeResult::class, $refused->toDate);
        self::assertNotContains(EstimateView::WARNING_FORECAST_INCOMPLETE, $refused->warnings);

        $incomplete = $this->estimates([], [new FakeForecastSource([], ['invoicing.invoices'])])->of(2026);
        self::assertInstanceOf(RegimeResult::class, $incomplete->yearEnd);
        self::assertContains(EstimateView::WARNING_FORECAST_INCOMPLETE, $incomplete->warnings);
        self::assertSame(['invoicing.invoices'], $incomplete->forecast?->unavailableSources);
        self::assertTrue($incomplete->forecast->incomplete);

        $complete = $this->estimates([], [new FakeForecastSource([$this->expected('a', '2026-12-01', 121_000)])])->of(2026);
        self::assertNotContains(EstimateView::WARNING_FORECAST_INCOMPLETE, $complete->warnings);
        self::assertFalse($complete->forecast?->incomplete);
        self::assertSame([], $complete->forecast->unverified, 'Not a VAT payer: the document counts gross.');
        self::assertSame(121_000, $complete->forecast->totalOf(AmountKind::Income)->counted->minor());
    }

    public function testAPastYearEndsWithWhatWasPaidAndNextYearHasNoYearEnd(): void
    {
        Clock::set(new MockClock('2027-02-01 10:00:00', 'UTC'));
        $forecast = new FakeForecastSource([$this->expected('a', '2027-03-01', 605_000)]);

        $past = $this->estimates([], [$forecast])->of(2026);
        self::assertSame($past->toDate, $past->yearEnd, 'A past year is over: its year end is what was paid.');
        self::assertNull($past->yearEndReason);
        self::assertNull($past->forecast);
        self::assertSame([], $forecast->asked);
        self::assertCount(12, $past->accruals['actual'] ?? []);

        $next = $this->estimates([], [$forecast])->of(2028);
        self::assertNull($next->yearEnd);
        self::assertSame(EstimateView::TO_DATE_YEAR_NOT_STARTED, $next->yearEndReason);
        self::assertSame([], $forecast->asked);
    }

    public function testAccrualMonthsSumToTheYear(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $amounts = [$this->amount('a', '2026-01-10', 100_000, 100_000), $this->amount('b', '2026-03-10', 31_000_000, 31_000_000)];
        $costs = [new ActualAmount('purchasing.payments', new Subject('received_document', 'd', null), AmountKind::Cost, new \DateTimeImmutable('2026-03-12', new \DateTimeZone('Europe/Prague')), Money::ofMinor(1_000_000, Currency::CZK), Money::ofMinor(1_000_000, Currency::CZK), Money::ofMinor(0, Currency::CZK), null, false)];
        $forecast = new FakeForecastSource([$this->expected('c', '2026-12-15', 60_000_000, ForecastCertainty::Plan)]);

        $estimate = $this->estimates([new FakeAmountSource('invoicing.payments', $amounts), new FakeAmountSource('purchasing.payments', $costs)], [$forecast])->of(2026);

        self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
        self::assertSame(['actual', 'lump_60'], array_keys($estimate->accruals));
        self::assertSame(30_000_000, $estimate->accruals['actual'][2]->profit->minor(), 'The profit of a month is its income less its costs.');
        foreach ($estimate->yearEnd->methods as $method) {
            $months = $estimate->accruals[$method->method->value];
            self::assertCount(12, $months);
            self::assertSame($method->totals->levies->minor(), array_sum(array_map(static fn (AccrualMonth $month): int => $month->levies->minor(), $months)), 'The months carry the levies of the year, to the heller.');
            [$january, $february, $march] = [$months[0], $months[1], $months[2]];
            self::assertSame(['2026-01', 100_000], [$january->month, $january->profit->minor()]);
            self::assertSame($january->minimum->minor(), $january->levies->minor(), 'A month of low profit carries its smallest advances.');
            self::assertSame([0, $february->minimum->minor()], [$february->profit->minor(), $february->levies->minor()], 'A month without profit carries its smallest advances.');
            self::assertGreaterThan(0, $february->minimum->minor());
            self::assertGreaterThan($march->minimum->minor(), $march->levies->minor());
            self::assertEqualsWithDelta(2 * $march->levies->minor(), $months[11]->levies->minor(), 2, 'The rest follows the profit: December brings twice what March does, give or take a heller.');
        }
    }

    public function testWithoutIncomeTheAccrualsFollowTheSmallestAdvances(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));

        $estimate = $this->estimates([], [new FakeForecastSource()])->of(2026);

        $months = $estimate->accruals['actual'] ?? [];
        self::assertCount(12, $months);
        $levies = $estimate->yearEnd?->method(ExpenseMethod::Actual)->totals->levies->minor();
        self::assertSame($levies, array_sum(array_map(static fn (AccrualMonth $month): int => $month->levies->minor(), $months)));
        // The yearly minimum is 35 % of the wage while the advances were 40 % to June: the levies follow the advances, lower.
        self::assertGreaterThan($months[11]->levies->minor(), $months[0]->levies->minor());
        self::assertLessThan($months[0]->minimum->minor(), $months[0]->levies->minor());
    }

    public function testWithoutActivityTheAccrualsAreZero(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'none', '2026-01-01'));

        $estimate = $this->estimates([], [new FakeForecastSource()])->of(2026);

        self::assertSame(array_fill(0, 12, [0, 0]), array_map(static fn (AccrualMonth $month): array => [$month->minimum->minor(), $month->levies->minor()], $estimate->accruals['actual'] ?? []));
    }

    public function testStatusesOfTheMonthsToComeReachOnlyTheYearEnd(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'secondary', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-12-01'));
        $this->writes()->addStatus(new AddStatusInput('flat_rate_band', '1', '2026-12-01'));

        $estimate = $this->estimates([], [new FakeForecastSource()])->of(2026);

        self::assertNotContains(CzechSoleTrader::WARNING_FLAT_RATE_NOT_COMPUTED, $estimate->toDate->warnings ?? []);
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_NOT_COMPUTED, $estimate->warnings);
        self::assertNotContains('mixed_year', $estimate->toDate->unverified ?? []);
        self::assertContains('mixed_year', $estimate->unverified);
    }

    public function testTheVatPayerDayCountsGrossBeforeAndNetAfterInTheEstimate(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-11-12'));

        $estimate = $this->estimates([
            new FakeAmountSource('invoicing.payments', [
                $this->amount('a', '2026-11-11', 1_210_000, 1_000_000),
                $this->amount('b', '2026-11-12', 1_210_000, 1_000_000),
            ]),
            new FakeAmountSource('purchasing.payments', []),
        ])->of(2026);

        self::assertInstanceOf(RegimeResult::class, $estimate->toDate);
        self::assertSame(2_210_000, $estimate->toDate->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertCount(11, $estimate->toDate->months, 'To date counts January to November, the month of today.');
        self::assertNull($estimate->yearEnd);
        self::assertSame(EstimateView::YEAR_END_CASH_FLOW_NOT_INSTALLED, $estimate->yearEndReason);
        self::assertContains('vat_cash_basis', $estimate->unverified);
        self::assertContains('insurance_profit_definition', $estimate->unverified);
        self::assertSame([], $estimate->warnings);
        self::assertSame('cz.sole_trader', $estimate->toDate->regime);
    }

    public function testWithoutInvoicingOrPurchasingTheEstimateSaysWhy(): void
    {
        $estimate = $this->estimates([])->of(2026);

        self::assertContains(EstimateView::WARNING_SOURCES_UNAVAILABLE, $estimate->warnings);
        self::assertNotNull($estimate->amounts);
        self::assertSame(['invoicing.payments', 'purchasing.payments'], $estimate->amounts->unavailableSources);
        self::assertContains(CzechSoleTrader::WARNING_ACTIVITY_ASSUMED_MAIN, $estimate->warnings);
    }

    public function testNextYearIsNotStartedAnEarlierYearHasNoRulesAndOnlyTheOwnerReads(): void
    {
        $next = $this->estimates([])->of(2027);
        self::assertNull($next->toDate);
        self::assertSame(EstimateView::TO_DATE_YEAR_NOT_STARTED, $next->toDateReason);

        $before = $this->estimates([])->of(2025);
        self::assertNull($before->toDate);
        self::assertSame(EstimateView::TO_DATE_RULES_NOT_AVAILABLE, $before->toDateReason);

        foreach ([1999, 2028] as $year) {
            try {
                $this->estimates([])->of($year);
                self::fail('The year '.$year.' is refused.');
            } catch (TaxesRefused $refused) {
                self::assertSame('invalid_year', $refused->reason());
            }
        }

        $this->signIn($this->member);
        $this->expectExceptionObject(TaxesRefused::ownerOnly());
        $this->estimates([])->of(2026);
    }

    public function testTheFirstYearIsAskedAndHasNoRules(): void
    {
        $estimate = $this->estimates([])->of(2000);

        self::assertNull($estimate->toDate);
        self::assertSame(EstimateView::TO_DATE_RULES_NOT_AVAILABLE, $estimate->toDateReason);
    }

    public function testTheEstimateJoinsWhatTheAmountsAndTheRegimeLeaveUnverifiedOrOut(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'secondary', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-06-01'));

        $estimate = $this->estimates([new FakeAmountSource('invoicing.payments', []), new FakeAmountSource('purchasing.payments', [])])->of(2026);

        self::assertSame(['insurance_profit_definition', 'mixed_year'], $estimate->unverified);
        self::assertSame(['non_deductible_costs', 'assets', 'assets_and_depreciation', 'loss_carry_forward', 'starter_relief'], $estimate->knownGaps);
    }

    public function testALegalFormNoRegimeComputesIsAConflict(): void
    {
        $estimates = new Estimates(self::getContainer()->get(ProfileReads::class), $this->amounts([]), [], self::getContainer()->get(InstanceClock::class));

        try {
            $estimates->of(2026);
            self::fail('Without a regime there is no estimate.');
        } catch (TaxesRefused $refused) {
            self::assertSame('no_regime', $refused->reason());
        }
    }

    public function testTheContainerGivesTheCzechSoleTrader(): void
    {
        $estimate = self::getContainer()->get(Estimates::class)->of(2026);

        self::assertSame('cz.sole_trader', $estimate->toDate?->regime);
    }

    /**
     * @param list<ActualAmountSource>   $sources
     * @param list<ForecastAmountSource> $forecasts
     */
    private function estimates(array $sources, array $forecasts = []): Estimates
    {
        $container = self::getContainer();

        return new Estimates($container->get(ProfileReads::class), $this->amounts($sources), [new CzechSoleTrader()], $container->get(InstanceClock::class), $forecasts, new YearEndForecast($sources));
    }

    private function expected(string $id, string $on, int $gross, ForecastCertainty $certainty = ForecastCertainty::Document, AmountKind $kind = AmountKind::Income, bool $restricted = false, string $source = 'invoicing.invoices'): ForecastAmount
    {
        return new ForecastAmount($source, $restricted ? null : new Subject('invoice', $id, null), $kind, new \DateTimeImmutable($on, new \DateTimeZone('Europe/Prague')), Money::ofMinor($gross, Currency::CZK), $certainty);
    }

    /**
     * @param list<ActualAmountSource> $sources
     */
    private function amounts(array $sources): Amounts
    {
        $container = self::getContainer();

        return new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), new NullLogger());
    }

    /**
     * @return array{int, int, int, int} the gross, net, VAT and counted
     */
    private function figures(AmountTotals $totals): array
    {
        return [$totals->gross->minor(), $totals->net->minor(), $totals->vat->minor(), $totals->counted->minor()];
    }

    private function amount(string $id, string $paidOn, int $gross, int $net): ActualAmount
    {
        $day = new \DateTimeImmutable($paidOn, new \DateTimeZone('Europe/Prague'));

        return new ActualAmount('invoicing.payments', new Subject('invoice', $id, '/api/v1/invoices/'.$id), AmountKind::Income, $day, Money::ofMinor($gross, Currency::CZK), Money::ofMinor($net, Currency::CZK), Money::ofMinor($gross - $net, Currency::CZK), $day, false);
    }
}
