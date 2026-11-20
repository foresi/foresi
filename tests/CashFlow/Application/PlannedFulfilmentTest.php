<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\HiddenTotal;
use Foresi\CashFlow\Application\PlannedFulfilment;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Foresi\Tests\CashFlow\Support\SpyLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * C13 and C16: a planned occurrence leaves the forecast when a document of its
 * counterparty sits in its period, or a bank movement meets its match rule;
 * and C15: a source whose documents the caller may not read gives totals only.
 */
final class PlannedFulfilmentTest extends CashFlowTestCase
{
    private const string ZONE = 'Europe/Prague';

    public function testThePeriodIsTheCycleOfTheItem(): void
    {
        $monthly = $this->planned(on: '2026-11-20', repeat: 'month');
        $quarterly = $this->planned(on: '2026-11-20', repeat: 'month', every: 3);
        $weekly = $this->planned(on: '2026-11-19', repeat: 'week');
        $daily = $this->planned(on: '2026-11-19', repeat: 'day', every: 2);
        $yearly = $this->planned(on: '2026-11-20', repeat: 'year');
        $once = $this->planned(on: '2026-12-10');

        self::assertSame(['2026-12-01', '2026-12-31'], $this->period($monthly, '2026-12-20'));
        self::assertSame(['2026-10-01', '2026-12-31'], $this->period($quarterly, '2026-12-20'));
        self::assertSame(['2026-11-16', '2026-11-22'], $this->period($weekly, '2026-11-22'), 'A Sunday ends its ISO week.');
        self::assertSame(['2026-11-20', '2026-11-21'], $this->period($daily, '2026-11-21'));
        self::assertSame(['2027-01-01', '2027-12-31'], $this->period($yearly, '2027-11-20'));
        self::assertSame(['2026-11-17', '2026-12-10'], $this->period($once, '2026-12-10'), 'A one-off item runs from its creation to its day.');
    }

    public function testADocumentOfTheCounterpartyInThePeriodReplacesTheRecurringOccurrence(): void
    {
        $landlord = $this->contact('Landlord Example s.r.o.', '11223344');
        $rent = $this->planned(on: '2026-11-25', repeat: 'month', counterpartyId: $landlord->id());
        $once = $this->planned(on: '2026-11-26', name: 'One-off fee', counterpartyId: $landlord->id());
        $documents = new FakeActualSource('test.documents', [
            $this->actual('2026-11-03', '10000.00', FlowDirection::Out, counterpartyId: $landlord->id()),
            $this->actual('2026-12-05', '10000.00', FlowDirection::In, counterpartyId: $landlord->id()),
            $this->actual('2027-01-05', '10000.00', FlowDirection::Out, counterpartyId: $this->customer->id()),
        ]);

        $forecast = $this->forecast([$documents]);

        self::assertSame(['2026-12-25', '2027-01-25'], array_slice($this->days($forecast, $rent), 0, 2), 'November has the landlord\'s document; December has one in the other direction and January one of another contact.');
        self::assertSame(['2026-11-26'], $this->days($forecast, $once), 'A one-off item is never replaced by the counterparty alone.');
        self::assertSame('2026-11-01', $documents->asked[0][0], 'Asked once from the first period that matters.');
        self::assertCount(1, $documents->asked);
    }

    public function testABankMovementMeetingTheMatchRuleFulfilsTheOccurrence(): void
    {
        $social = $this->matched('Social insurance advance', '5720.00', '000000-0123456789/0710', '4402051234', '50.00');
        $tax = $this->matched('Health insurance advance', '3143.00', null, '7701', null);
        $bank = new FakeActualSource('test.bank', [
            // Paid early this month: the account padded and spaced differently, 30 CZK off.
            $this->actual('2026-11-09', '5690.00', FlowDirection::Out, account: '123456789 / 0710', symbol: '4402051234'),
            // December: within the tolerance but another variable symbol.
            $this->actual('2026-12-02', '5720.00', FlowDirection::Out, account: '123456789/0710', symbol: '999'),
            // Health insurance: any account, the symbol with leading zeros, the exact amount in November and 1 CZK off in December.
            $this->actual('2026-11-10', '3143.00', FlowDirection::Out, account: '1/0300', symbol: '0007701'),
            $this->actual('2026-12-10', '3142.00', FlowDirection::Out, account: '1/0300', symbol: '7701'),
            // A document never meets a match rule.
            $this->actual('2027-01-03', '3143.00', FlowDirection::Out, symbol: '7701', counterpartyId: $this->customer->id()),
        ]);

        $forecast = $this->forecast([$bank]);

        self::assertSame('2026-12-20', $this->days($forecast, $social)[0]);
        self::assertSame(['2026-12-20', '2027-01-20'], array_slice($this->days($forecast, $tax), 0, 2));
    }

    public function testAFailingActualSourceSuppressesNothingAndIsListed(): void
    {
        $landlord = $this->contact('Landlord Example s.r.o.', '11223344');
        $rent = $this->planned(on: '2026-11-25', repeat: 'month', counterpartyId: $landlord->id());
        $broken = new FakeActualSource('test.broken', [$this->actual('2026-11-03', '10000.00', FlowDirection::Out, counterpartyId: $landlord->id())], fails: true);

        $forecast = $this->forecast([$broken]);

        self::assertSame('2026-11-25', $this->days($forecast, $rent)[0]);
        self::assertSame(['test.broken'], $forecast->unavailableSources);
        self::assertTrue($forecast->isIncomplete());
    }

    public function testASourceTheCallerMayNotReadGivesTheTotalsOnly(): void
    {
        $flow = static fn (string $id, string $on, string $amount, FlowCategory $category): ExpectedFlow => new ExpectedFlow('test.invoices', new FlowSubject('invoice', $id), FlowDirection::In, Money::of($amount, Currency::CZK), new \DateTimeImmutable($on, new \DateTimeZone(self::ZONE)), $category, FlowCertainty::Document, 'Invoice '.$id);
        $hidden = new FakeSource('test.invoices', [$flow('a', '2026-11-18', '100.00', FlowCategory::Revenue), $flow('b', '2026-11-19', '50.00', FlowCategory::Revenue), $flow('c', '2026-11-19', '7.00', FlowCategory::Other)], details: false);
        $this->planned(on: '2026-11-20');

        $forecast = $this->forecasts([$hidden, new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], [])->of();

        $week = $forecast->periods[0];
        self::assertSame(['planned_item'], array_map(static fn (\Foresi\CashFlow\Application\ForecastLine $line): string => $line->flow->subject->type, $week->lines));
        self::assertSame('15700', (string) $week->inflows['revenue']->plus($week->inflows['other'])->minor());
        self::assertCount(2, $week->hidden);
        self::assertSame(['test.invoices', 'other', 700, 1], [$week->hidden[0]->source, $week->hidden[0]->category->value, $week->hidden[0]->amount->minor(), $week->hidden[0]->count]);
        self::assertSame(['revenue', 15000, 2], [$week->hidden[1]->category->value, $week->hidden[1]->amount->minor(), $week->hidden[1]->count]);
        self::assertSame(['test.invoices'], $forecast->restrictedSources);
        self::assertSame(15700 - 1000000, $week->closing->minus($week->opening)->minor(), 'The hidden lines still move the balance.');
    }

    public function testAnItemRepeatingEveryTwoYearsHasThePreviousYearInItsPeriod(): void
    {
        $item = $this->planned(on: '2026-11-20', repeat: 'year', every: 2);

        self::assertSame(['2026-01-01', '2027-12-31'], $this->period($item, '2027-11-20'));
    }

    public function testTheFirstAndTheLastDayOfThePeriodCountAndTheDaysAroundItDoNot(): void
    {
        $item = $this->matched('Health insurance advance', '3143.00', null, '7701', null);
        $day = new \DateTimeImmutable('2026-12-20', new \DateTimeZone(self::ZONE));
        $on = fn (string $date): ActualFlow => $this->actual($date, '3143.00', FlowDirection::Out, symbol: '7701');

        self::assertTrue(PlannedFulfilment::isFulfilled($item, $day, [$on('2026-12-01')]));
        self::assertTrue(PlannedFulfilment::isFulfilled($item, $day, [$on('2026-12-31')]));
        self::assertFalse(PlannedFulfilment::isFulfilled($item, $day, [$on('2026-11-30')]));
        self::assertFalse(PlannedFulfilment::isFulfilled($item, $day, [$on('2027-01-01')]));
        self::assertFalse(PlannedFulfilment::isFulfilled($item, $day, [$this->actual('2026-12-10', '3143.00', FlowDirection::In, symbol: '7701')]), 'Money coming in is no payment going out.');
    }

    public function testTheAccountOfTheRuleMustBeTheOneOfTheMovement(): void
    {
        self::assertTrue($this->meets('123456789/0710', null, null, account: '123456789/0710'));
        self::assertFalse($this->meets('123456789/0710', null, null), 'A movement without an account cannot meet a rule that names one.');
        self::assertFalse($this->meets('123456789/0710', null, null, account: '987654321/0710'));
        self::assertFalse($this->meets(null, null, null, account: '123456789/0710'), 'A rule that names nothing meets nothing.');
    }

    public function testAccountsAreComparedWithoutSpacesZerosAndInCapitals(): void
    {
        self::assertTrue($this->meets('cz6508000000192000145399', null, null, account: 'CZ65 0800 0000 1920 0014 5399'));
        self::assertTrue($this->meets('19-123/0100', null, null, account: '000019-0000000123/0100'));
        self::assertTrue($this->meets('0-123/0100', null, null, account: '123 / 0100'));
        self::assertFalse($this->meets('19-123/0100', null, null, account: '20-123/0100'), 'Another prefix.');
        self::assertFalse($this->meets('19-123/0100', null, null, account: '123/0100'), 'A prefix against none.');
        self::assertFalse($this->meets('19-123/0100', null, null, account: '191-23/0100'), 'The prefix and the number do not run together.');
        self::assertFalse($this->meets('19-123/0100', null, null, account: '19-124/0100'), 'Another number.');
        self::assertFalse($this->meets('123/0100', null, null, account: '124/0100'));
        self::assertFalse($this->meets('123/0100', null, null, account: '123/0800'), 'Another bank.');
        self::assertFalse($this->meets('123/0100', null, null, account: 'X123/0100'), 'Not a Czech number at the start.');
        self::assertFalse($this->meets('123/0100', null, null, account: '123/01009'), 'Not a Czech number at the end.');
        self::assertFalse($this->meets('12-3/0100', null, null, account: '1-23/0100'));
    }

    public function testTheVariableSymbolIsComparedWithoutLeadingZeros(): void
    {
        self::assertTrue($this->meets(null, '0007701', null, symbol: '7701'));
        self::assertTrue($this->meets(null, '7701', null, symbol: '0007701'));
        self::assertFalse($this->meets(null, '7701', null, symbol: '7702'));
        self::assertFalse($this->meets(null, '7701', null));
    }

    public function testWithoutAToleranceTheAmountMustBeTheExactOne(): void
    {
        self::assertTrue($this->meets(null, '7701', null, symbol: '7701', amount: '5000.00'));
        self::assertFalse($this->meets(null, '7701', null, symbol: '7701', amount: '5000.01'));
        self::assertFalse($this->meets(null, '7701', null, symbol: '7701', amount: '4999.99'));
        self::assertTrue($this->meets(null, '7701', '0.01', symbol: '7701', amount: '5000.01'));
        self::assertFalse($this->meets(null, '7701', '0.01', symbol: '7701', amount: '5000.02'));
    }

    /**
     * A one-off item of 5,000.00 CZK going out on 2026-11-20 with a rule; whether an actual of 2026-11-20 meets it.
     */
    private function meets(?string $ruleAccount, ?string $ruleSymbol, ?string $tolerance, ?string $account = null, ?string $symbol = null, string $amount = '5000.00'): bool
    {
        $item = $this->writes()->create(new CreatePlannedItemInput('out', 'Insurance', 'taxes', '5000.00', '2026-11-20', matchAccount: $ruleAccount, matchVariableSymbol: $ruleSymbol, matchTolerance: $tolerance));
        $day = new \DateTimeImmutable('2026-11-20', new \DateTimeZone(self::ZONE));

        return PlannedFulfilment::isFulfilled($item, $day, [$this->actual('2026-11-20', $amount, FlowDirection::Out, account: $account, symbol: $symbol)]);
    }

    public function testASourceTheCallerMayNotReadIsListedOnceWhateverHowManyGiveItsKey(): void
    {
        $forecast = $this->forecasts([new FakeSource('test.a', details: false), new FakeSource('test.a', details: false), new FakeSource('test.b', details: false)], [])->of();

        self::assertSame(['test.a', 'test.b'], $forecast->restrictedSources);
    }

    public function testTheTotalsOfARestrictedSourceAreGroupedAndOrderedBySourceDirectionAndCategory(): void
    {
        $flow = static fn (string $source, string $id, FlowDirection $direction, FlowCategory $category, string $amount): ExpectedFlow => new ExpectedFlow($source, new FlowSubject('invoice', $id), $direction, Money::of($amount, Currency::CZK), new \DateTimeImmutable('2026-11-18', new \DateTimeZone(self::ZONE)), $category, FlowCertainty::Document, 'Invoice '.$id);
        $short = new FakeSource('x', [
            $flow('x', 'a', FlowDirection::Out, FlowCategory::Revenue, '2.00'),
            $flow('x', 'b', FlowDirection::In, FlowCategory::Revenue, '1.00'),
            $flow('x', 'c', FlowDirection::In, FlowCategory::Other, '3.00'),
            $flow('x', 'd', FlowDirection::Out, FlowCategory::Revenue, '5.00'),
        ], details: false);
        $long = new FakeSource('xp', [$flow('xp', 'e', FlowDirection::In, FlowCategory::Revenue, '4.00')], details: false);

        $forecast = $this->forecasts([$short, $long], [])->of();

        self::assertSame(
            [['xp', 'in', 'revenue', 400, 1], ['x', 'in', 'other', 300, 1], ['x', 'in', 'revenue', 100, 1], ['x', 'out', 'revenue', 700, 2]],
            array_map(static fn (HiddenTotal $total): array => [$total->source, $total->direction->value, $total->category->value, $total->amount->minor(), $total->count], $forecast->periods[0]->hidden),
        );
    }

    public function testTheActualSourcesAreAskedForTheWholeSpanOfThePeriodsAndNotAtAllWhenNothingCanBeFulfilled(): void
    {
        $plain = $this->planned(on: '2026-11-20', repeat: 'month', name: 'Recurring without a contact');
        $once = $this->planned(on: '2026-11-21', name: 'One-off with a contact', counterpartyId: $this->customer->id());
        $documents = new FakeActualSource('test.documents');
        $this->forecast([$documents]);
        self::assertSame([], $documents->asked, 'Neither a recurring item without a contact nor a one-off one without a rule can be fulfilled.');

        $this->planned(on: '2027-03-15', repeat: 'year', name: 'Insurance', counterpartyId: $this->customer->id());
        $this->planned(on: '2026-11-18', repeat: 'week', name: 'Cleaning', counterpartyId: $this->customer->id());
        $documents = new FakeActualSource('test.documents');
        $this->forecast([$documents]);

        self::assertSame([['2026-11-16', '2027-12-31']], $documents->asked, 'From the start of the earliest period to the end of the latest, whichever line comes last.');
        self::assertNotSame($plain->id(), $once->id());
    }

    public function testAnActualSourceThatFailsIsLoggedAndTheNextOneStillCounts(): void
    {
        $landlord = $this->contact('Landlord Example s.r.o.', '11223344');
        $rent = $this->planned(on: '2026-11-25', repeat: 'month', counterpartyId: $landlord->id());
        $broken = new FakeActualSource('test.broken', [$this->actual('2026-11-03', '10000.00', FlowDirection::Out, counterpartyId: $landlord->id())], fails: true);
        $working = new FakeActualSource('test.working', [$this->actual('2026-11-03', '10000.00', FlowDirection::Out, counterpartyId: $landlord->id())]);
        $logger = new SpyLogger();

        $forecast = $this->forecasts([new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], [$broken, $working], $logger)->of();

        self::assertSame('2026-12-25', $this->days($forecast, $rent)[0]);
        self::assertSame(['test.broken'], $forecast->unavailableSources);
        self::assertCount(1, $logger->records);
        self::assertSame(['error', 'The cash-flow actual source {source} failed.', ['source', 'exception']], [$logger->records[0]['level'], $logger->records[0]['message'], array_keys($logger->records[0]['context'])]);
        self::assertSame('test.broken', $logger->records[0]['context']['source']);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    /**
     * @param list<ActualFlowSource> $actuals
     */
    private function forecast(array $actuals): Forecast
    {
        return $this->forecasts([new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], $actuals)->of();
    }

    /**
     * @param list<\Foresi\CashFlow\Contract\CashFlowSource> $sources
     * @param list<ActualFlowSource>                         $actuals
     */
    private function forecasts(array $sources, array $actuals, ?LoggerInterface $logger = null): Forecasts
    {
        $container = self::getContainer();

        return new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), $logger ?? new NullLogger(), $sources, [], $actuals);
    }

    /**
     * @return list<string> the days the item is counted on
     */
    private function days(Forecast $forecast, PlannedItem $item): array
    {
        $days = [];
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                if ($line->flow->subject->id === $item->id()) {
                    $days[] = $line->countedOn->format('Y-m-d');
                }
            }
        }

        return $days;
    }

    /**
     * @return array{string, string}
     */
    private function period(PlannedItem $item, string $day): array
    {
        [$first, $last] = PlannedFulfilment::period($item, new \DateTimeImmutable($day, new \DateTimeZone(self::ZONE)));

        return [$first->format('Y-m-d'), $last->format('Y-m-d')];
    }

    private function matched(string $name, string $amount, ?string $account, ?string $symbol, ?string $tolerance): PlannedItem
    {
        return $this->writes()->create(new CreatePlannedItemInput('out', $name, 'taxes', $amount, '2026-11-20', repeat: 'month', matchAccount: $account, matchVariableSymbol: $symbol, matchTolerance: $tolerance));
    }

    private function actual(string $on, string $amount, FlowDirection $direction, ?string $account = null, ?string $symbol = null, ?string $counterpartyId = null): ActualFlow
    {
        return new ActualFlow('test.actuals', new FlowSubject('test', $on.$amount), $direction, Money::of($amount, Currency::CZK), new \DateTimeImmutable($on, new \DateTimeZone(self::ZONE)), $counterpartyId, $account, $symbol);
    }

    private function contact(string $name, string $number): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party;
    }
}
