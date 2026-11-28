<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\Country;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\YearEndForecast;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\MonthStatus;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\Tests\TaxesAndContributions\Support\DatedAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The status changes in the middle of 2026, end to end (Review focus 2 and
 * 3): each splits the year where it starts, in the actual amounts, the
 * advances, the settlement and the estimate flows of the cash flow alike.
 * - VAT payer from 12 November: a payment on the 11th counts gross, one on
 *   the 12th net.
 * - The flat-rate tax from July to September: those months pay the band and
 *   nothing else, the others the ordinary advances.
 * - The activity secondary to November and main in December.
 */
final class StatusSplitTest extends TaxesTestCase
{
    public function testTheVatPayerDaySplitsTheAmountsAndTheSettlementButNotTheAdvances(): void
    {
        Clock::set(new MockClock('2027-02-01 10:00:00', 'UTC'));
        [$payer, $payerFlows] = $this->vatPayerFrom('2026-11-12');
        [$earlier, $earlierFlows] = $this->vatPayerFrom('2026-11-11');

        self::assertSame(2_210_000_00, $this->income($payer), 'The 11th counts 1,210,000 with VAT, the 12th 1,000,000 without.');
        self::assertSame(2_000_000_00, $this->income($earlier), 'A day earlier both count without VAT.');
        self::assertContains('vat_cash_basis', $payer->unverified);

        // The advances follow the minimums and the year before, not the amounts.
        self::assertEquals($this->advances($earlier), $this->advances($payer));
        // The settlement follows the year's own levies, so the split reaches it.
        foreach ([Authority::IncomeTax, Authority::Social, Authority::Health] as $authority) {
            $settlement = $this->payment($payer->payments, $authority->value.'.settlement.2026');
            $levy = $this->levy($payer->yearEnd, $authority);
            self::assertSame($levy - $this->advanced($payer, $authority), $settlement->amount->minor(), $authority->value);
            self::assertGreaterThan($this->payment($earlier->payments, $authority->value.'.settlement.2026')->amount->minor(), $settlement->amount->minor(), $authority->value);
            self::assertSame($settlement->amount->minor(), $this->payment($payerFlows, $authority->value.'.settlement.2026')->amount->minor(), 'The cash flow gets the same settlement.');
            self::assertNotSame($settlement->amount->minor(), $this->payment($earlierFlows, $authority->value.'.settlement.2026')->amount->minor());
        }
    }

    public function testInTheHourBetweenPragueAndUtcMidnightTheVatPayerDayHasBegun(): void
    {
        // 23:30 UTC on the 11th is 00:30 in Prague on the 12th.
        Clock::set(new MockClock('2026-11-11 23:30:00', 'UTC'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        FirmVat::from($this->em, 'yes', '2026-11-12');
        $estimates = $this->estimates([
            $this->paid('invoicing.payments', AmountKind::Income, '2026-11-11', 121_000_00, 100_000_00),
            $this->paid('invoicing.payments', AmountKind::Income, '2026-11-12', 121_000_00, 100_000_00),
        ]);

        $estimate = $estimates->of(2026);

        self::assertSame('2026-11-12', $estimate->today->format('Y-m-d'));
        self::assertSame(221_000_00, $this->income($estimate), 'The payment of the 12th is already in, without its VAT.');
    }

    public function testEnteringAndLeavingTheFlatRateMidYearSplitsTheAdvancesTheSettlementAndTheFlows(): void
    {
        Clock::set(new MockClock('2026-06-15 10:00:00', 'UTC'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('flat_rate_band', '1', '2026-07-01'));
        $this->writes()->addStatus(new AddStatusInput('flat_rate_band', 'none', '2026-10-01'));
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $estimates = $this->estimates([$this->paid('invoicing.payments', AmountKind::Income, '2026-03-10', 600_000_00, 600_000_00)]);

        $estimate = $estimates->of(2026);

        $keys = array_map(static fn (DuePayment $payment): string => $payment->key(), $estimate->payments);
        foreach (['07', '08', '09'] as $month) {
            self::assertContains('flat_rate.advance.2026-'.$month, $keys);
            self::assertNotContains('social.advance.2026-'.$month, $keys, $month);
            self::assertNotContains('health.advance.2026-'.$month, $keys, $month);
        }
        foreach (['06', '10'] as $month) {
            self::assertContains('social.advance.2026-'.$month, $keys);
            self::assertContains('health.advance.2026-'.$month, $keys);
            self::assertNotContains('flat_rate.advance.2026-'.$month, $keys);
        }
        // The first month of the regime is due by the 20th of the next month, the others by their own 20th.
        self::assertSame(['2026-08-20', '2026-08-20', '2026-09-21'], array_map(fn (string $month): string => $this->payment($estimate->payments, 'flat_rate.advance.2026-'.$month)->dueOn->format('Y-m-d'), ['07', '08', '09']));
        self::assertSame([572_000, 500_500], [$this->payment($estimate->payments, 'social.advance.2026-06')->amount->minor(), $this->payment($estimate->payments, 'social.advance.2026-10')->amount->minor()], 'Back in the ordinary tax, October pays the minimum of its own rules.');

        $flows = array_map(static fn (DuePayment $payment): string => $payment->key(), $estimates->openPayments(new \DateTimeImmutable('2026-12-31', new \DateTimeZone('Europe/Prague'))));
        self::assertContains('flat_rate.advance.2026-07', $flows);
        self::assertContains('social.advance.2026-10', $flows);
        self::assertNotContains('social.advance.2026-08', $flows);

        // After the year: nine ordinary months settle their own levies; the flat months are covered by the band.
        Clock::set(new MockClock('2027-02-01 10:00:00', 'UTC'));
        $past = $estimates->of(2026);
        self::assertInstanceOf(RegimeResult::class, $past->yearEnd);
        self::assertSame(['2026-07', '2026-08', '2026-09'], array_values(array_map(static fn (MonthStatus $month): string => $month->month, array_filter($past->yearEnd->months, static fn (MonthStatus $month): bool => null !== $month->flatRateBand))));
        self::assertContains('flat_rate_mixed_year', $past->unverified);
        foreach ([Authority::Social, Authority::Health] as $authority) {
            $levy = $this->levy($past->yearEnd, $authority);
            $rest = $levy - $this->advanced($past, $authority);
            $key = $authority->value.($rest > 0 ? '.settlement.2026' : '.overpayment.2026');
            self::assertSame(abs($rest), $this->payment($past->payments, $key)->amount->minor(), $authority->value);
        }
        self::assertSame([], array_filter($past->payments, static fn (DuePayment $payment): bool => Authority::FlatRate === $payment->authority && DuePayment::KIND_ADVANCE !== $payment->kind), 'The flat rate has no settlement.');
    }

    public function testFromSecondaryToMainInDecemberSplitsTheAdvancesTheSettlementAndTheFlows(): void
    {
        $this->writes()->addStatus(new AddStatusInput('activity', 'secondary', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-12-01'));
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $estimates = $this->estimates([$this->paid('invoicing.payments', AmountKind::Income, '2026-03-10', 600_000_00, 600_000_00)]);

        // In November: no social advance while secondary without a base from the year before, the minimum of a main one in December.
        $keys = array_map(static fn (DuePayment $payment): string => $payment->key(), $estimates->of(2026)->payments);
        self::assertNotContains('social.advance.2026-11', $keys);
        self::assertContains('health.advance.2026-11', $keys);
        $december = $this->payment($estimates->of(2026)->payments, 'social.advance.2026-12');
        self::assertSame([500_500, '2026-12-31'], [$december->amount->minor(), $december->dueOn->format('Y-m-d')]);
        $flows = $estimates->openPayments(new \DateTimeImmutable('2026-12-31', new \DateTimeZone('Europe/Prague')));
        self::assertSame(500_500, $this->payment($flows, 'social.advance.2026-12')->amount->minor());
        self::assertCount(1, array_filter($flows, static fn (DuePayment $payment): bool => Authority::Social === $payment->authority && DuePayment::KIND_ADVANCE === $payment->kind && 2026 === $payment->taxYear()), 'One social advance in 2026, December\'s.');

        // After the year: the mixed year settles social on its profit less December's advance.
        Clock::set(new MockClock('2027-02-01 10:00:00', 'UTC'));
        $past = $estimates->of(2026);
        self::assertInstanceOf(RegimeResult::class, $past->yearEnd);
        self::assertSame(['secondary', 'main'], array_values(array_unique(array_map(static fn (MonthStatus $month): string => $month->activity, $past->yearEnd->months))));
        self::assertContains('mixed_year', $past->unverified);
        $social = $this->payment($past->payments, 'social.settlement.2026');
        self::assertSame($this->levy($past->yearEnd, Authority::Social) - 500_500, $social->amount->minor());
        self::assertSame($social->amount->minor(), $this->payment($estimates->openPayments(new \DateTimeImmutable('2027-12-31', new \DateTimeZone('Europe/Prague'))), 'social.settlement.2026')->amount->minor());
    }

    /**
     * @return array{EstimateView, list<DuePayment>}
     */
    private function vatPayerFrom(string $day): array
    {
        $this->setUp();
        Clock::set(new MockClock('2027-02-01 10:00:00', 'UTC'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        FirmVat::from($this->em, 'yes', $day);
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $estimates = $this->estimates([
            $this->paid('invoicing.payments', AmountKind::Income, '2026-11-11', 1_210_000_00, 1_000_000_00),
            $this->paid('invoicing.payments', AmountKind::Income, '2026-11-12', 1_210_000_00, 1_000_000_00),
        ]);

        return [$estimates->of(2026), $estimates->openPayments(new \DateTimeImmutable('2027-12-31', new \DateTimeZone('Europe/Prague')))];
    }

    private function income(EstimateView $estimate): int
    {
        return $estimate->toDate?->method(ExpenseMethod::Actual)->totals->income->minor() ?? self::fail('No estimate.');
    }

    private function levy(?RegimeResult $result, Authority $authority): int
    {
        $totals = $result?->method(ExpenseMethod::Actual)->totals ?? self::fail('No year end.');

        return match ($authority) {
            Authority::Social => $totals->social->minor(),
            Authority::Health => $totals->health->minor(),
            default => $totals->incomeTax->minor() - $totals->childBonus->minor(),
        };
    }

    private function advanced(EstimateView $estimate, Authority $authority): int
    {
        return array_sum(array_map(static fn (DuePayment $payment): int => $payment->amount->minor(), array_filter($estimate->payments, static fn (DuePayment $payment): bool => $authority === $payment->authority && DuePayment::KIND_ADVANCE === $payment->kind)));
    }

    /**
     * @return list<array{string, int}>
     */
    private function advances(EstimateView $estimate): array
    {
        return array_values(array_map(static fn (DuePayment $payment): array => [$payment->key(), $payment->amount->minor()], array_filter($estimate->payments, static fn (DuePayment $payment): bool => DuePayment::KIND_ADVANCE === $payment->kind)));
    }

    private function paid(string $source, AmountKind $kind, string $on, int $gross, int $net): ActualAmount
    {
        $day = new \DateTimeImmutable($on, new \DateTimeZone('Europe/Prague'));

        return new ActualAmount($source, new Subject('invoice', $on, null), $kind, $day, Money::ofMinor($gross, Currency::of('CZK')), Money::ofMinor($net, Currency::of('CZK')), Money::ofMinor($gross - $net, Currency::of('CZK')), $day, false);
    }

    /**
     * @param list<ActualAmount> $income
     */
    private function estimates(array $income): Estimates
    {
        $container = self::getContainer();
        $sources = [new DatedAmountSource('invoicing.payments', $income), new DatedAmountSource('received-documents.payments')];
        $amounts = new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), new NullLogger());

        return new Estimates($container->get(ProfileReads::class), $amounts, [new CzechSoleTrader()], $container->get(InstanceClock::class), [], new YearEndForecast($sources));
    }

    /**
     * @param list<DuePayment> $payments
     */
    private function payment(array $payments, string $key): DuePayment
    {
        foreach ($payments as $payment) {
            if ($key === $payment->key()) {
                return $payment;
            }
        }

        self::fail('No payment '.$key);
    }
}
