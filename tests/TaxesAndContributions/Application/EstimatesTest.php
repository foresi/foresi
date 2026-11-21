<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

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
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\Tests\TaxesAndContributions\Support\FakeAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\NullLogger;

/**
 * The estimate "to date" (Goals 1-3): the regime of the owner's legal form
 * over the actual amounts and the months up to today, with "year end" apart
 * and empty until the forecast is read.
 */
final class EstimatesTest extends TaxesTestCase
{
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
        self::assertSame(EstimateView::YEAR_END_FORECAST_NOT_BUILT, $estimate->yearEndReason);
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
     * @param list<ActualAmountSource> $sources
     */
    private function estimates(array $sources): Estimates
    {
        $container = self::getContainer();

        return new Estimates($container->get(ProfileReads::class), $this->amounts($sources), [new CzechSoleTrader()], $container->get(InstanceClock::class));
    }

    /**
     * @param list<ActualAmountSource> $sources
     */
    private function amounts(array $sources): Amounts
    {
        $container = self::getContainer();

        return new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), new NullLogger());
    }

    private function amount(string $id, string $paidOn, int $gross, int $net): ActualAmount
    {
        $day = new \DateTimeImmutable($paidOn, new \DateTimeZone('Europe/Prague'));

        return new ActualAmount('invoicing.payments', new Subject('invoice', $id, '/api/v1/invoices/'.$id), AmountKind::Income, $day, Money::ofMinor($gross, Currency::CZK), Money::ofMinor($net, Currency::CZK), Money::ofMinor($gross - $net, Currency::CZK), $day, false);
    }
}
