<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;
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
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The forecast as another module reads it (ForecastReader): the sources asked
 * as the forecast asks them, an excluded source never called, excluded
 * categories and certainties left out, a restricted source without subjects,
 * a fulfilled planned occurrence left out, and the days a read may cover.
 */
final class ForecastReadsTest extends CashFlowTestCase
{
    private const string ZONE = 'Europe/Prague';

    public function testTheContractReadsTheSourcesOfTheInstalledModules(): void
    {
        $this->planned(on: '2026-11-25', name: 'Office rent');
        $reader = self::getContainer()->get(ForecastReader::class);

        $read = $reader->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')));

        $planned = array_values(array_filter($read->flows, static fn (ReadFlow $flow): bool => PlannedItemFlows::KEY === $flow->source));
        self::assertCount(1, $planned);
        self::assertSame(['2026-11-25', 1000000, FlowCertainty::Plan], [$planned[0]->on->format('Y-m-d'), $planned[0]->amountCzk->minor(), $planned[0]->certainty]);
    }

    public function testAnExcludedSourceIsNeverCalled(): void
    {
        $spy = new FakeSource('x.y', [$this->flow('x.y', '1', '2026-11-20', '1000.00')], fails: true);
        $other = new FakeSource('test.other', [$this->flow('test.other', '2', '2026-11-21', '2000.00')]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31'), excludeSources: ['x.y']), [$spy, $other]);

        self::assertCount(0, $spy->asked);
        self::assertSame(['test.other'], array_map(static fn (ReadFlow $flow): string => $flow->source, $read->flows));
        self::assertSame([], $read->unavailableSources);
        self::assertFalse($read->incomplete);
    }

    public function testExcludedCategoriesAreLeftOut(): void
    {
        $flows = [];
        foreach (FlowCategory::cases() as $index => $category) {
            $flows[] = $this->flow('test.all', (string) $index, '2026-11-20', '100.00', category: $category);
        }
        $excluded = [FlowCategory::Taxes, FlowCategory::Insurance, FlowCategory::Vat, FlowCategory::Loans, FlowCategory::OwnerDraws];

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31'), excludeCategories: $excluded), [new FakeSource('test.all', $flows)]);

        $categories = array_map(static fn (ReadFlow $flow): string => $flow->category->value, $read->flows);
        sort($categories);
        self::assertSame(['other', 'payroll', 'purchases', 'rent', 'revenue', 'software'], $categories);
    }

    public function testOnlyTheCertaintiesAskedForAreReturned(): void
    {
        $source = new FakeSource('test.mixed', [
            $this->flow('test.mixed', '1', '2026-11-20', '100.00', FlowCertainty::Document),
            $this->flow('test.mixed', '2', '2026-11-20', '200.00', FlowCertainty::Plan),
            $this->flow('test.mixed', '3', '2026-11-20', '300.00', FlowCertainty::Estimate),
        ]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31'), certainties: [FlowCertainty::Estimate, FlowCertainty::Document]), [$source]);

        self::assertSame(['document', 'estimate'], array_map(static fn (ReadFlow $flow): string => $flow->certainty->value, $read->flows));
    }

    public function testToMayReachPastTheTwelveMonthHorizonUpTo24Months(): void
    {
        $source = new FakeSource('test.far', [
            $this->flow('test.far', '1', '2028-06-30', '100.00'),
            $this->flow('test.far', '2', '2028-11-17', '200.00'),
            $this->flow('test.far', '3', '2028-11-18', '300.00'),
        ]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2028-11-17')), [$source]);

        self::assertSame(['2028-06-30', '2028-11-17'], array_map(static fn (ReadFlow $flow): string => $flow->on->format('Y-m-d'), $read->flows));
        self::assertSame([['2026-11-17', '2028-11-17', '2026-11-17 Europe/Prague']], $source->asked);
        $this->assertRefused('to_too_far', fn (): ForecastRead => $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2028-11-18')), [$source]));
        $this->assertRefused('to_before_from', fn (): ForecastRead => $this->read(new ForecastQuery($this->day('2026-11-20'), $this->day('2026-11-19')), [$source]));
    }

    public function testARestrictedSourceReturnsFlowsWithoutSubject(): void
    {
        $hidden = new FakeSource('test.hidden', [$this->flow('test.hidden', '1', '2026-11-20', '1000.00')], details: false);
        $open = new FakeSource('test.open', [$this->flow('test.open', '2', '2026-11-20', '2000.00')]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')), [$hidden, $open]);

        self::assertSame(['test.hidden'], $read->restrictedSources);
        self::assertCount(2, $read->flows);
        [$first, $second] = $read->flows;
        self::assertSame(['test.hidden', null, 100000, null], [$first->source, $first->subject, $first->amountCzk->minor(), $first->label]);
        self::assertSame(['test.open', '2', 'Invoice 2'], [$second->source, $second->subject?->id, $second->label]);
    }

    public function testAFulfilledPlannedOccurrenceIsNotReturned(): void
    {
        $item = $this->writes()->create(new CreatePlannedItemInput('out', 'Social insurance advance', 'taxes', '5720.00', '2026-11-20', repeat: 'month', matchVariableSymbol: '4402051234'));
        $bank = new FakeActualSource('test.bank', [new ActualFlow('test.bank', new FlowSubject('bank_movement', 'm-1'), FlowDirection::Out, Money::of('5720.00', Currency::CZK), $this->day('2026-11-09'), variableSymbol: '4402051234')]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2027-01-31')), [new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], [$bank]);

        self::assertSame(['2026-12-20', '2027-01-20'], array_map(static fn (ReadFlow $flow): string => $flow->on->format('Y-m-d'), $read->flows));
        self::assertSame($item->id(), $read->flows[0]->subject?->id);
    }

    public function testAnOverdueFlowIsReadOnTheFirstDayInCzkWithItsOwnAmount(): void
    {
        $source = new FakeSource('test.euro', [$this->flow('test.euro', '1', '2026-11-02', '100.00', currency: Currency::EUR)]);

        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')), [$source]);

        $flow = $read->flows[0];
        self::assertSame(['2026-11-17', 10000, 245000], [$flow->on->format('Y-m-d'), $flow->amount->minor(), $flow->amountCzk->minor()]);
        self::assertSame([FlowFlag::Overdue], $flow->flags);
    }

    public function testAFailingSourceMakesTheReadIncomplete(): void
    {
        $read = $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')), [new FakeSource('test.broken', [$this->flow('test.broken', '1', '2026-11-20', '1.00')], fails: true)]);

        self::assertSame([], $read->flows);
        self::assertSame(['test.broken'], $read->unavailableSources);
        self::assertTrue($read->incomplete);
    }

    public function testInTheHourAfterPragueMidnightTheReadStartsOnThePragueDay(): void
    {
        // 23:30 UTC on the 17th is 00:30 on the 18th in Prague.
        Clock::set(new MockClock('2026-11-17 23:30:00', 'UTC'));
        $source = new FakeSource('test.today', [$this->flow('test.today', '1', '2026-11-17', '100.00')]);

        $this->assertRefused('from_in_past', fn (): ForecastRead => $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')), [$source]));
        $read = $this->read(new ForecastQuery($this->day('2026-11-18'), $this->day('2026-12-31')), [$source]);

        self::assertSame(['2026-11-18'], array_map(static fn (ReadFlow $flow): string => $flow->on->format('Y-m-d'), $read->flows));
        self::assertSame([FlowFlag::Overdue], $read->flows[0]->flags);
    }

    public function testAGuestMayNotReadTheForecast(): void
    {
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', fn (): ForecastRead => $this->read(new ForecastQuery($this->day('2026-11-17'), $this->day('2026-12-31')), []));
    }

    /**
     * @param list<CashFlowSource>   $sources
     * @param list<ActualFlowSource> $actuals
     */
    private function read(ForecastQuery $query, array $sources, array $actuals = []): ForecastRead
    {
        $container = self::getContainer();
        $reads = new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: $sources, actualSources: $actuals);

        return $reads->read($query);
    }

    /**
     * A flow of the source of that key, as a real source gives its own key.
     */
    private function flow(string $source, string $id, string $on, string $amount, FlowCertainty $certainty = FlowCertainty::Document, FlowCategory $category = FlowCategory::Revenue, Currency $currency = Currency::CZK): ExpectedFlow
    {
        return new ExpectedFlow($source, new FlowSubject('invoice', $id), FlowDirection::In, Money::of($amount, $currency), $this->day($on), $category, $certainty, 'Invoice '.$id);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone(self::ZONE));
    }

    private function assertRefused(string $reason, \Closure $read): void
    {
        try {
            $read();
            self::fail('Expected the read to be refused with '.$reason.'.');
        } catch (CashFlowRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }
}
