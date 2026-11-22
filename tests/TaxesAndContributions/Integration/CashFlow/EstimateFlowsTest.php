<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastRead;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\DuePayments;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Application\YearEndForecast;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\PossibleDuplicate;
use Foresi\TaxesAndContributions\Integration\CashFlow\BankLevyPayments;
use Foresi\TaxesAndContributions\Integration\CashFlow\DuplicatePlannedItems;
use Foresi\TaxesAndContributions\Integration\CashFlow\EstimateFlows;
use Foresi\TaxesAndContributions\Integration\CashFlow\ForecastAmounts;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\TaxesAndContributions\Support\CountingSource;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Foresi\Tests\TaxesAndContributions\Support\FixedToken;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The payments of the estimate in the cash flow (Goal 5, B4, B5): estimate
 * flows in the categories taxes and insurance, never read again from inside
 * the forecast, as category totals to anyone but the owner, left out once
 * the bank paid them, and the planned items they may duplicate named but
 * never ended.
 */
final class EstimateFlowsTest extends TaxesTestCase
{
    private const string SOCIAL_ACCOUNT = '123-456789/0100';
    private const string SYMBOL = '7805121234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->writes()->setAuthority(new SetAuthorityAccountInput('social', self::SOCIAL_ACCOUNT, self::SYMBOL));
    }

    public function testTheEstimateSourceInsideAForecastDoesNotRecurse(): void
    {
        $spy = new CountingSource();
        $reads = $this->forecastReads([$spy]);
        $spy->inner = $this->flowsWith($this->estimates($reads));

        $read = $reads->read(new ForecastQuery($this->day('2026-11-20'), $this->day('2027-12-31')));

        self::assertSame(1, $spy->calls, 'The year end inside the source reads the forecast without the source.');
        $keys = $this->keys($read->flows);
        self::assertContains('social.overpayment.2026', $keys, 'The settlement of this year needs the year end, read from the forecast: the 5,720 advances to June paid more than the 35 % minimum of the year.');
        self::assertContains('social.advance.2027-12', $keys, 'Next year has its advances.');

        $full = self::getContainer()->get(ForecastReader::class)->read(new ForecastQuery($this->day('2026-11-20'), $this->day('2027-12-31')));
        self::assertSame($keys, $this->keys($full->flows), 'The installed module gives the same flows, once each.');
        self::assertSame([], $full->unavailableSources);
    }

    public function testTheFlowsAreEstimatesOfTaxesAndInsuranceOnTheirDueDays(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2026', lastKnownTax: '200000.00'));

        $flows = $this->flows();

        $tax = $flows['income_tax.advance.2026-12'];
        self::assertSame([FlowCategory::Taxes, FlowCertainty::Estimate, FlowDirection::Out, [FlowFlag::Estimated]], [$tax->category, $tax->certainty, $tax->direction, $tax->flags]);
        self::assertSame(['2026-12-15', 5_000_000, 'Europe/Prague'], [$tax->expectedOn->format('Y-m-d'), $tax->amount->minor(), $tax->expectedOn->getTimezone()->getName()]);
        self::assertSame([EstimateFlows::KEY, EstimateFlows::SUBJECT, '/api/v1/taxes-and-contributions/estimate?year=2026'], [$tax->source, $tax->subject->type, $tax->subject->url]);
        self::assertSame('Daň z příjmů (finanční úřad) – záloha 12/2026', $tax->label);
        self::assertSame([FlowCategory::Insurance, '2026-12-31', 500_500], [$flows['social.advance.2026-12']->category, $flows['social.advance.2026-12']->expectedOn->format('Y-m-d'), $flows['social.advance.2026-12']->amount->minor()]);
        self::assertSame([FlowCategory::Insurance, '2027-01-08'], [$flows['health.advance.2026-12']->category, $flows['health.advance.2026-12']->expectedOn->format('Y-m-d')]);
        $back = $flows['income_tax.overpayment.2026'];
        self::assertSame(['2027-05-03', FlowCategory::Taxes, FlowDirection::In, 20_000_000], [$back->expectedOn->format('Y-m-d'), $back->category, $back->direction, $back->amount->minor()], 'Without income the advances come back.');
        self::assertArrayNotHasKey('health.settlement.2026', $flows, 'With no income, the health minimum is what the advances paid.');
        self::assertSame(800, $flows['health.overpayment.2026']->amount->minor(), 'Twelve advances rounded up each pay 8 CZK more than the year rounded up once.');
        self::assertArrayNotHasKey('social.advance.2027-01', $this->flows(to: '2026-12-31'), 'Nothing after the last day asked.');
        self::assertArrayNotHasKey('income_tax.overpayment.2026', $this->flows(to: '2026-12-31'));
    }

    public function testAnOverpaymentComesInAsAnExpectedInflow(): void
    {
        $this->writes()->setYear(new SetYearInputsInput('2026', ['actual'], 'actual', previousSocialBase: '1200000.00', previousOverviewFiledOn: '2026-04-20'));

        $back = $this->flows()['social.overpayment.2026'];

        self::assertSame([FlowDirection::In, FlowCategory::Insurance, '2027-06-11'], [$back->direction, $back->category, $back->expectedOn->format('Y-m-d')]);
        self::assertStringContainsString('přeplatek', $back->label);
    }

    public function testAMatchingBankMovementReplacesTheEstimateFlow(): void
    {
        $bank = new FakeActualSource('banking.movements', [
            new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm-oct'), FlowDirection::Out, $this->czk(5_005), $this->day('2026-10-30'), counterpartyAccount: '000123-0000456789/0100', variableSymbol: '07805121234'),
            // A document of the same amount pays no levy, nor does a movement with another symbol.
            new ActualFlow('invoicing.invoices', new FlowSubject('invoice', 'i1'), FlowDirection::Out, $this->czk(5_005), $this->day('2026-09-30'), counterpartyId: 'c1', counterpartyAccount: self::SOCIAL_ACCOUNT, variableSymbol: self::SYMBOL),
            new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm-other'), FlowDirection::Out, $this->czk(5_005), $this->day('2026-08-30'), counterpartyAccount: self::SOCIAL_ACCOUNT, variableSymbol: '111'),
        ]);
        $estimates = $this->estimates($this->forecastReads([]), new DuePayments([new BankLevyPayments(new RuleMovements([$bank]))]));

        $flows = $this->flows($this->flowsWith($estimates));

        self::assertArrayNotHasKey('social.advance.2026-10', $flows, 'The October advance is paid by the movement.');
        self::assertArrayHasKey('social.advance.2026-09', $flows);
        self::assertArrayHasKey('social.advance.2026-08', $flows);
        self::assertSame([['2026-01-01', '2026-11-20']], array_slice($bank->asked, 0, 1));

        $paid = $this->payment($estimates->of(2026), 'social.advance.2026-10');
        self::assertSame([DuePayment::STATE_PAID, 'm-oct', '2026-10-30'], [$paid->state, $paid->paidBy, $paid->paidOn?->format('Y-m-d')]);
    }

    public function testAnUnpaidPastAdvanceIsOverdueAndStaysInTheForecastOnlyWithAMatchRule(): void
    {
        $estimate = self::getContainer()->get(Estimates::class)->of(2026);

        self::assertSame(DuePayment::STATE_OVERDUE, $this->payment($estimate, 'social.advance.2026-10')->state);
        self::assertSame(DuePayment::STATE_OVERDUE, $this->payment($estimate, 'health.advance.2026-09')->state);
        self::assertSame(DuePayment::STATE_EXPECTED, $this->payment($estimate, 'social.advance.2026-11')->state);
        self::assertContains(EstimateView::WARNING_NO_MATCH_RULE, $estimate->warnings, 'Health has no account in the profile.');

        $flows = $this->flows();
        self::assertSame([FlowFlag::Estimated], $flows['social.advance.2026-10']->flags, 'The forecast itself marks it overdue.');
        self::assertSame('2026-10-31', $flows['social.advance.2026-10']->expectedOn->format('Y-m-d'));
        self::assertArrayNotHasKey('health.advance.2026-09', $flows, 'Without an account the bank cannot say it is unpaid.');
        self::assertArrayHasKey('health.advance.2026-11', $flows);
    }

    public function testTheDayBoundaryBetweenPragueAndUtcMidnight(): void
    {
        $estimates = self::getContainer()->get(Estimates::class);

        Clock::set(new MockClock('2026-11-30 22:59:59', 'UTC'));
        self::assertSame(DuePayment::STATE_EXPECTED, $this->payment($estimates->of(2026), 'social.advance.2026-11')->state, '23:59 in Prague: due today.');

        Clock::set(new MockClock('2026-11-30 23:00:00', 'UTC'));
        self::assertSame(DuePayment::STATE_OVERDUE, $this->payment($estimates->of(2026), 'social.advance.2026-11')->state, 'Midnight in Prague is 1 December, though UTC is still 30 November.');
    }

    public function testNoFlowsBeforeAnActivityIsEntered(): void
    {
        $this->writes()->removeStatus(new RemoveStatusInput($this->reads()->profile()->statuses[0]->id));
        FirmVat::from($this->em, 'yes', '2026-11-12');

        self::assertSame([], $this->flows(), 'Another status is no activity.');
    }

    public function testAMemberSeesEstimateFlowsAsCategoryTotalsOnly(): void
    {
        $owner = $this->estimateFlows(self::getContainer()->get(ForecastReader::class)->read(new ForecastQuery($this->day('2026-11-20'), $this->day('2026-12-31'))));
        self::assertNotNull($owner[0]->subject);
        self::assertNotNull($owner[0]->label);

        $this->signIn($this->member);
        $read = self::getContainer()->get(ForecastReader::class)->read(new ForecastQuery($this->day('2026-11-20'), $this->day('2026-12-31')));

        $member = $this->estimateFlows($read);
        self::assertContains(EstimateFlows::KEY, $read->restrictedSources);
        self::assertCount(\count($owner), $member);
        foreach ($member as $flow) {
            self::assertNull($flow->subject, 'A member never sees which payment it is.');
            self::assertNull($flow->label);
        }
        self::assertSame($this->total($owner), $this->total($member), 'The totals stay right.');
    }

    public function testOnlyTheOwnerWithTaxesReadMayReadTheFlowsOneByOne(): void
    {
        self::assertTrue($this->flowsWith(self::getContainer()->get(Estimates::class))->mayReadDetails());
        self::assertFalse($this->flowsWith(self::getContainer()->get(Estimates::class), new FixedToken([ApiScope::from('cashflow:read')]))->mayReadDetails());
        self::assertTrue($this->flowsWith(self::getContainer()->get(Estimates::class), new FixedToken([ApiScope::from('taxes:read')]))->mayReadDetails());

        $this->signIn($this->member);
        self::assertFalse($this->flowsWith(self::getContainer()->get(Estimates::class))->mayReadDetails());
        self::assertFalse($this->flowsWith(self::getContainer()->get(Estimates::class), new FixedToken([ApiScope::from('taxes:read')]))->mayReadDetails());
    }

    public function testAnActiveTaxesPlannedItemIsListedAsAPossibleDuplicate(): void
    {
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $tax = $writes->create(new CreatePlannedItemInput('out', 'Income tax advance', 'taxes', '5000.00', '2026-12-15', repeat: 'month'));
        $writes->create(new CreatePlannedItemInput('out', 'Social insurance', 'insurance', '5005.00', '2026-12-31'));
        $writes->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '10000.00', '2026-12-01', repeat: 'month'));
        $this->em->flush();

        $estimate = self::getContainer()->get(Estimates::class)->of(2026);

        self::assertContains(EstimateView::WARNING_POSSIBLE_DUPLICATES, $estimate->warnings);
        $names = array_map(static fn (PossibleDuplicate $item): string => $item->name, $estimate->possibleDuplicates);
        sort($names);
        self::assertSame(['Income tax advance', 'Social insurance'], $names);
        $listed = array_values(array_filter($estimate->possibleDuplicates, static fn (PossibleDuplicate $item): bool => $item->id === $tax->id()));
        self::assertSame(['taxes', '/cash-flow/planned-costs/'.$tax->id()], [$listed[0]->category, $listed[0]->url]);

        $this->em->clear();
        $kept = self::getContainer()->get(CashFlowStore::class)->plannedItem($tax->id());
        self::assertTrue($kept?->isIncluded(), 'The warning ends nothing and leaves out nothing.');
        self::assertCount(2, self::getContainer()->get(DuplicatePlannedItems::class)->activeLevyItems(), 'Both stay active until the owner ends them.');
    }

    public function testWithoutDuplicatesOrWhenTheyCannotBeReadThereIsNoWarning(): void
    {
        $estimate = self::getContainer()->get(Estimates::class)->of(2026);
        self::assertSame([], $estimate->possibleDuplicates);
        self::assertNotContains(EstimateView::WARNING_POSSIBLE_DUPLICATES, $estimate->warnings);
    }

    public function testIntoTheNextYearTheFlowsKeepWhatLastYearLeftOpen(): void
    {
        Clock::set(new MockClock('2027-01-20 10:00:00', 'UTC'));

        $open = self::getContainer()->get(Estimates::class)->openPayments($this->day('2027-12-31'));

        self::assertSame(range(0, \count($open) - 1), array_keys($open));
        $keys = array_map(static fn (DuePayment $payment): string => $payment->key(), $open);
        self::assertContains('social.advance.2026-12', $keys, 'Last year\'s unpaid advance, overdue with its match rule.');
        self::assertContains('social.overpayment.2026', $keys, 'Last year\'s settlement, from what was paid.');
        self::assertContains('social.advance.2027-01', $keys);
        self::assertNotContains('health.advance.2026-12', $keys, 'Overdue without a match rule.');
        self::assertNotContains('social.advance.2028-01', $keys, 'Nothing after the last day.');
    }

    public function testThePlannedItemsAreReadFromTheForecastWithoutTheEstimate(): void
    {
        $day = $this->day('2026-12-15');
        $czk = $this->czk(5_000);
        $flow = static fn (?FlowSubject $subject, FlowCategory $category, FlowDirection $direction = FlowDirection::Out, ?string $label = 'Tax'): ReadFlow => new ReadFlow('cash-flow.planned_items', $subject, $direction, $czk, $czk, $day, $category, FlowCertainty::Plan, [], $label);
        $reader = new FakeForecastReader([
            $flow(null, FlowCategory::Taxes),
            $flow(new FlowSubject('invoice', 'i1'), FlowCategory::Taxes),
            $flow(new FlowSubject('planned_item', '019a0000-0000-7000-8000-0000000000a1'), FlowCategory::Taxes, label: 'Income tax'),
            $flow(new FlowSubject('planned_item', '019a0000-0000-7000-8000-0000000000a1'), FlowCategory::Taxes, label: 'Income tax'),
            $flow(new FlowSubject('planned_item', '019a0000-0000-7000-8000-0000000000b2'), FlowCategory::Insurance, FlowDirection::In, null),
        ]);
        $container = self::getContainer();

        $items = new DuplicatePlannedItems($reader, $container->get(InstanceClock::class), $container->get(UrlGeneratorInterface::class))->activeLevyItems();

        self::assertSame([['019a0000-0000-7000-8000-0000000000a1', 'Income tax', 'taxes', '/cash-flow/planned-costs/019a0000-0000-7000-8000-0000000000a1'], ['019a0000-0000-7000-8000-0000000000b2', '019a0000-0000-7000-8000-0000000000b2', 'insurance', '/cash-flow/planned-income/019a0000-0000-7000-8000-0000000000b2']], array_map(static fn (PossibleDuplicate $item): array => [$item->id, $item->name, $item->category, $item->url], $items));
        self::assertSame([0, 1], array_keys($items));
        $query = $reader->queries[0];
        self::assertSame([EstimateFlows::KEY], $query->excludeSources);
        self::assertSame([FlowCertainty::Plan], $query->certainties);
        self::assertSame(['revenue', 'purchases', 'rent', 'software', 'payroll', 'vat', 'loans', 'owner_draws', 'other'], array_map(static fn (FlowCategory $category): string => $category->value, $query->excludeCategories));
        self::assertSame(['2026-11-20', '2027-11-20'], [$query->from->format('Y-m-d'), $query->to->format('Y-m-d')]);
    }

    /**
     * @param list<CashFlowSource> $sources
     */
    private function forecastReads(array $sources): ForecastReads
    {
        $container = self::getContainer();

        return new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: $sources);
    }

    private function estimates(ForecastReads $reads, DuePayments $payments = new DuePayments()): Estimates
    {
        $container = self::getContainer();

        return new Estimates($container->get(ProfileReads::class), $container->get(Amounts::class), [new CzechSoleTrader()], $container->get(InstanceClock::class), [new ForecastAmounts($reads)], $container->get(YearEndForecast::class), new NullLogger(), $payments);
    }

    private function flowsWith(Estimates $estimates, ?ApiTokenContext $tokens = null): EstimateFlows
    {
        $container = self::getContainer();

        return new EstimateFlows($estimates, $container->get(TaxesAccess::class), $tokens ?? $container->get(ApiTokenContext::class), $container->get(TranslatorInterface::class), $container->get(UrlGeneratorInterface::class));
    }

    /**
     * The flows of the installed source up to the day, by payment.
     *
     * @return array<string, ExpectedFlow>
     */
    private function flows(?EstimateFlows $source = null, string $to = '2027-12-31'): array
    {
        $source ??= self::getContainer()->get(EstimateFlows::class);
        $today = $this->day('2026-11-20');
        $flows = [];
        foreach ($source->flows($today, $this->day($to), $today) as $flow) {
            $flows[$flow->subject->id] = $flow;
        }

        return $flows;
    }

    /**
     * @param list<ReadFlow> $flows
     *
     * @return list<string>
     */
    private function keys(array $flows): array
    {
        return array_map(static fn (ReadFlow $flow): string => $flow->subject->id ?? '', $this->estimateFlowsOf($flows));
    }

    /**
     * @return list<ReadFlow>
     */
    private function estimateFlows(ForecastRead $read): array
    {
        return $this->estimateFlowsOf($read->flows);
    }

    /**
     * @param list<ReadFlow> $flows
     *
     * @return list<ReadFlow>
     */
    private function estimateFlowsOf(array $flows): array
    {
        return array_values(array_filter($flows, static fn (ReadFlow $flow): bool => EstimateFlows::KEY === $flow->source));
    }

    /**
     * @param list<ReadFlow> $flows
     */
    private function total(array $flows): int
    {
        return array_sum(array_map(static fn (ReadFlow $flow): int => FlowDirection::In === $flow->direction ? $flow->amountCzk->minor() : -$flow->amountCzk->minor(), $flows));
    }

    private function payment(EstimateView $estimate, string $key): DuePayment
    {
        foreach ($estimate->payments as $payment) {
            if ($key === $payment->key()) {
                return $payment;
            }
        }

        self::fail('No payment '.$key);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function czk(int $czk): Money
    {
        return Money::ofMinor($czk * 100, Currency::CZK);
    }
}
