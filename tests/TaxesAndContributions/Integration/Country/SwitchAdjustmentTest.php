<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\Country;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechDeadlines;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Contract\RateSource;
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
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Contract\SwitchAdjustment;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\TaxesAndContributions\Support\DatedAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Foresi\Tests\TaxesAndContributions\Support\RecordingLogger;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The method-switch adjustment in the estimate (old T10 A, ZDP § 23 (8)):
 * with the actual costs filed for 2026 and a lump sum for 2027, the
 * receivables open on 31 December 2026, less the payables, raise the 2026
 * base. Its tax is a warning with the amount and a payment of 2027, due by
 * the 2027 return deadline, in the payments and the cash flow alike.
 */
final class SwitchAdjustmentTest extends TaxesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2027-03-10 10:00:00', 'UTC'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
    }

    public function testSwitchFromActualToLumpAddsOpenReceivablesAtYearEnd(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $this->writes()->setYear(new SetYearInputsInput('2027', ['actual', 'lump_60'], 'lump_60'));
        [$income, $costs] = $this->sources();

        $estimates = $this->estimates([$income, $costs]);
        $estimate = $estimates->of(2027);

        $switch = $estimate->toDate?->switchAdjustment;
        self::assertInstanceOf(SwitchAdjustment::class, $switch);
        self::assertSame(['2026-12-31'], array_values(array_unique($income->openAsked)));
        self::assertSame([121_000_00, 24_200_00, 96_800_00, 400_000_00, 14_520_00], [$switch->receivables->minor(), $switch->payables->minor(), $switch->baseChange->minor(), $switch->previousTaxBase?->minor(), $switch->tax->minor()]);
        self::assertContains('switch_adjustment', $estimate->warnings);
        self::assertContains('switch_adjustment', $estimate->unverified);
        self::assertContains('switch_adjustment_insurance', $estimate->unverified);
        self::assertContains('switch_adjustment_inventory_and_reserves', $estimate->knownGaps);
        self::assertSame(0, $estimate->toDate?->method(ExpenseMethod::Lump60)->totals->income->minor(), 'Nothing of 2026 is in the 2027 totals.');

        $due = CzechDeadlines::returnDeadline(2027, ReturnRoute::Electronic, new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        $payment = $this->payment($estimate->payments, 'income_tax.switch_adjustment.2027');
        self::assertSame([DuePayment::STATE_EXPECTED, $due, 14_520_00], [$payment->state, $payment->dueOn->format('Y-m-d'), $payment->amount->minor()]);
        self::assertSame($due, $switch->dueOn->format('Y-m-d'));
        self::assertNotContains('income_tax.switch_adjustment.2027', array_map(static fn (DuePayment $payment): string => $payment->key(), $estimates->of(2026)->payments), 'The payment belongs to the year of the change.');

        $flows = $estimates->openPayments(new \DateTimeImmutable('2028-12-31', new \DateTimeZone('Europe/Prague')));
        self::assertSame(14_520_00, $this->payment($flows, 'income_tax.switch_adjustment.2027')->amount->minor(), 'The cash flow gets it as an estimate flow on its due day.');
        self::assertNotContains('income_tax.switch_adjustment.2027', array_map(static fn (DuePayment $payment): string => $payment->key(), $estimates->openPayments(new \DateTimeImmutable('2028-04-30', new \DateTimeZone('Europe/Prague')))));
    }

    public function testNoSwitchNoAdjustment(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2027', ['lump_60'], 'lump_60'));
        [$income, $costs] = $this->sources();

        $estimate = $this->estimates([$income, $costs])->of(2027);

        self::assertNull($estimate->toDate?->switchAdjustment);
        self::assertSame([], $income->openAsked, 'Without the method of 2026 filed, nothing open is read.');
        self::assertNotContains('switch_adjustment', $estimate->warnings);
        self::assertNotContains('switch_adjustment', $estimate->unverified);
        self::assertSame([], array_filter($estimate->payments, static fn (DuePayment $payment): bool => DuePayment::KIND_SWITCH_ADJUSTMENT === $payment->kind));

        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $this->writes()->setYear(new SetYearInputsInput('2027', ['actual'], 'actual'));
        $same = $this->estimates([$income, $costs])->of(2027);
        self::assertNull($same->toDate?->switchAdjustment, 'The same method both years.');
        self::assertSame(['2026-12-31'], array_values(array_unique($income->openAsked)));
    }

    public function testAVatPayerOnTheLastDayCountsTheOpenDocumentsWithoutVat(): void
    {
        FirmVat::from($this->em, 'yes', '2026-11-12');
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $this->writes()->setYear(new SetYearInputsInput('2027', ['lump_60'], 'lump_60'));

        $estimate = $this->estimates($this->sources())->of(2027);

        self::assertSame([100_000_00, 20_000_00, 80_000_00, 12_000_00], [$estimate->toDate?->switchAdjustment?->receivables->minor(), $estimate->toDate?->switchAdjustment?->payables->minor(), $estimate->toDate?->switchAdjustment?->baseChange->minor(), $estimate->toDate?->switchAdjustment?->tax->minor()]);
        self::assertContains('vat_cash_basis', $estimate->unverified);
    }

    public function testASourceThatCannotSayWhatIsOpenIsListedAsUnavailable(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $this->writes()->setYear(new SetYearInputsInput('2027', ['lump_60'], 'lump_60'));
        [$income] = $this->sources();
        $logger = new RecordingLogger();

        $estimate = $this->estimates([new DatedAmountSource('purchasing.payments', openFails: true), $income], $logger)->of(2027);

        self::assertContains(EstimateView::WARNING_SOURCES_UNAVAILABLE, $estimate->warnings, 'Only the open documents failed, and that says so too.');
        self::assertSame(121_000_00, $estimate->toDate?->switchAdjustment?->baseChange->minor(), 'Only what could be read counts, after the source that failed.');
        self::assertContains(['error', 'purchasing.payments'], array_map(static fn (array $record): array => [$record[0], $record[1]['source'] ?? null], $logger->records));
    }

    public function testTheFirstYearBeforeIsReadToo(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2000', ['actual'], 'actual'));
        [$income, $costs] = $this->sources();

        $this->estimates([$income, $costs])->of(2001);

        self::assertSame(['2000-12-31'], array_values(array_unique($income->openAsked)));
    }

    public function testAForeignOpenDocumentCountsAtTheRateOfTheLastDay(): void
    {
        $rates = new FakeRateSource();
        $rates->publish('2026-12-31', '25.000', '15.000');
        self::getContainer()->set(RateSource::class, $rates);
        $container = self::getContainer();
        $open = static fn (Currency $currency, int $gross, int $net): OpenAmount => new OpenAmount('invoicing.payments', new Subject('invoice', $currency->value, null), AmountKind::Income, Money::ofMinor($gross, $currency), Money::ofMinor($net, $currency), Money::ofMinor($gross - $net, $currency));
        $source = new DatedAmountSource('invoicing.payments', [], ['2026-12-31' => [$open(Currency::USD, 10_00, 10_00), $open(Currency::EUR, 121_00, 100_00)]]);

        $view = new Amounts($container->get(ProfileReads::class), [$source], $container->get(ExchangeRates::class), $container->get(InstanceClock::class), new NullLogger())
            ->openAt(new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('UTC')), $container->get(ProfileReads::class)->profile());

        self::assertSame(['2026-12-31', 'Europe/Prague', ['purchasing.payments'], ['USD']], [$view->day->format('Y-m-d'), $view->day->getTimezone()->getName(), $view->unavailableSources, $view->missingRates]);
        $income = $view->totalOf(AmountKind::Income);
        self::assertSame([3_025_00, 2_500_00, 525_00, 3_025_00], [$income->gross->minor(), $income->net->minor(), $income->vat->minor(), $income->counted->minor()], '121 EUR at 25.000; not a VAT payer, so gross.');
        self::assertSame(['fx_payment_day'], $view->unverified);
        self::assertSame(0, $view->totalOf(AmountKind::Cost)->counted->minor());
    }

    /**
     * @return array{DatedAmountSource, DatedAmountSource}
     */
    private function sources(): array
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $paid = static fn (string $source, AmountKind $kind, int $czk): ActualAmount => new ActualAmount($source, new Subject('invoice', $source, null), $kind, new \DateTimeImmutable('2026-06-10', $zone), Money::ofMinor($czk * 100, Currency::CZK), Money::ofMinor($czk * 100, Currency::CZK), Money::ofMinor(0, Currency::CZK), null, false);
        $open = static fn (string $source, AmountKind $kind, int $gross, int $net): OpenAmount => new OpenAmount($source, new Subject('document', $source.'-open', null), $kind, Money::ofMinor($gross * 100, Currency::CZK), Money::ofMinor($net * 100, Currency::CZK), Money::ofMinor(($gross - $net) * 100, Currency::CZK));

        return [
            new DatedAmountSource('invoicing.payments', [$paid('invoicing.payments', AmountKind::Income, 500_000)], ['2026-12-31' => [$open('invoicing.payments', AmountKind::Income, 121_000, 100_000)]]),
            new DatedAmountSource('purchasing.payments', [$paid('purchasing.payments', AmountKind::Cost, 100_000)], ['2026-12-31' => [$open('purchasing.payments', AmountKind::Cost, 24_200, 20_000)]]),
        ];
    }

    /**
     * @param list<ActualAmountSource> $sources
     */
    private function estimates(array $sources, LoggerInterface $logger = new NullLogger()): Estimates
    {
        $container = self::getContainer();
        $amounts = new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), $logger);

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
