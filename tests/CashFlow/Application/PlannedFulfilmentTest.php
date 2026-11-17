<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Application;

use App\CashFlow\Application\CashFlowAccess;
use App\CashFlow\Application\CashFlowStore;
use App\CashFlow\Application\CreatePlannedItemInput;
use App\CashFlow\Application\Forecast;
use App\CashFlow\Application\Forecasts;
use App\CashFlow\Application\PlannedFulfilment;
use App\CashFlow\Contract\ActualFlow;
use App\CashFlow\Contract\ActualFlowSource;
use App\CashFlow\Contract\ExpectedFlow;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowCertainty;
use App\CashFlow\Contract\FlowDirection;
use App\CashFlow\Contract\FlowSubject;
use App\CashFlow\Domain\PlannedItem;
use App\CashFlow\Infrastructure\Source\PlannedItemFlows;
use App\ExchangeRate\Application\ExchangeRates;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
use App\Tests\CashFlow\Support\CashFlowTestCase;
use App\Tests\CashFlow\Support\FakeActualSource;
use App\Tests\CashFlow\Support\FakeSource;
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
        self::assertSame('2026-11-01', $documents->asked[0][1], 'Asked once from the first period that matters.');
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

        $forecast = $this->forecasts([$hidden, new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], [])->of($this->firm->id());

        $week = $forecast->periods[0];
        self::assertSame(['planned_item'], array_map(static fn ($line): string => $line->flow->subject->type, $week->lines));
        self::assertSame('15700', (string) $week->inflows['revenue']->plus($week->inflows['other'])->minor());
        self::assertCount(2, $week->hidden);
        self::assertSame(['test.invoices', 'other', 700, 1], [$week->hidden[0]->source, $week->hidden[0]->category->value, $week->hidden[0]->amount->minor(), $week->hidden[0]->count]);
        self::assertSame(['revenue', 15000, 2], [$week->hidden[1]->category->value, $week->hidden[1]->amount->minor(), $week->hidden[1]->count]);
        self::assertSame(['test.invoices'], $forecast->restrictedSources);
        self::assertSame(15700 - 1000000, $week->closing->minus($week->opening)->minor(), 'The hidden lines still move the balance.');
    }

    /**
     * @param list<ActualFlowSource> $actuals
     */
    private function forecast(array $actuals): Forecast
    {
        return $this->forecasts([new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class))], $actuals)->of($this->firm->id());
    }

    /**
     * @param list<\App\CashFlow\Contract\CashFlowSource> $sources
     * @param list<ActualFlowSource>                      $actuals
     */
    private function forecasts(array $sources, array $actuals): Forecasts
    {
        $container = self::getContainer();

        return new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(TenantClock::class), $container->get(ExchangeRates::class), new NullLogger(), $sources, [], $actuals);
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
        return $this->writes()->create(new CreatePlannedItemInput($this->firm->id(), 'out', $name, 'taxes', $amount, '2026-11-20', repeat: 'month', matchAccount: $account, matchVariableSymbol: $symbol, matchTolerance: $tolerance));
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
