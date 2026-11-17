<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Application;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\CashFlowAccess;
use App\CashFlow\Application\CashFlowRefused;
use App\CashFlow\Application\CashFlowStore;
use App\CashFlow\Application\Forecast;
use App\CashFlow\Application\ForecastLine;
use App\CashFlow\Application\Forecasts;
use App\CashFlow\Application\RecordCheckpointInput;
use App\CashFlow\Application\SetExpectedDateInput;
use App\CashFlow\Application\SetMinimumBalanceInput;
use App\CashFlow\Contract\BalanceSource;
use App\CashFlow\Contract\CashFlowSource;
use App\CashFlow\Contract\ExpectedFlow;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowCertainty;
use App\CashFlow\Contract\FlowDirection;
use App\CashFlow\Contract\FlowFlag;
use App\CashFlow\Contract\FlowSubject;
use App\CashFlow\Contract\PeriodKind;
use App\CashFlow\Infrastructure\Source\PlannedItemFlows;
use App\ExchangeRate\Application\ExchangeRates;
use App\ExchangeRate\Domain\ExchangeRate;
use App\ExchangeRate\Domain\RateNotAvailable;
use App\Instance\Application\TenantClock;
use App\Shared\Application\RefusalKind;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\CashFlow\Support\CashFlowTestCase;
use App\Tests\CashFlow\Support\FakeBalanceSource;
use App\Tests\CashFlow\Support\FakeSource;
use App\Tests\CashFlow\Support\SpyLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The forecast of a firm (C2, C6–C10, C14): from the latest balance of each
 * account, every source's flows in CZK by week then month, overdue documents
 * in the first period, expected days, the lowest point and the shortfall,
 * and a failing source, balance source or rate left out and listed.
 */
final class ForecastsTest extends CashFlowTestCase
{
    public function testStartsFromTheCheckpointAndCountsThePlannedItemsByWeekThenMonth(): void
    {
        $this->checkpoint('2026-11-16', '100000.00');
        $this->planned();
        $this->planned(on: '2026-12-01', amount: '50000.00', direction: 'in', category: 'revenue', repeat: 'month', name: 'Retainer');

        $forecast = $this->forecast();

        self::assertSame('2026-11-17', $forecast->today->format('Y-m-d'));
        self::assertSame('2026-11-17', $forecast->from->format('Y-m-d'));
        self::assertSame(10000000, $forecast->opening->minor());
        self::assertSame('2026-11-16', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertFalse($forecast->stale);
        self::assertFalse($forecast->isIncomplete());
        self::assertCount(17, $forecast->periods);
        self::assertSame(PeriodKind::Week, $forecast->periods[6]->period->kind);
        self::assertSame(PeriodKind::Month, $forecast->periods[7]->period->kind);

        $first = $forecast->periods[0];
        self::assertSame(10000000, $first->opening->minor());
        self::assertSame(['rent' => 1000000], $this->minors($first->outflows));
        self::assertSame([], $first->inflows);
        self::assertSame(9000000, $first->closing->minor());
        self::assertSame(['Office rent'], $this->labels($first->lines));
        self::assertSame(FlowCertainty::Plan, $first->lines[0]->flow->certainty);
        self::assertSame(PlannedItemFlows::KEY, $first->lines[0]->flow->source);
        self::assertSame('2026-11-20', $first->lines[0]->countedOn->format('Y-m-d'));

        $december = $forecast->periods[2];
        self::assertSame('2026-W49', $december->period->key());
        self::assertSame(9000000, $december->opening->minor());
        self::assertSame(['revenue' => 5000000], $this->minors($december->inflows));
        self::assertSame(14000000, $december->closing->minor());
        // 1 January falls in the last week, 1 February opens the second month.
        self::assertSame(['Retainer'], $this->labels($forecast->periods[6]->lines));
        self::assertSame([], $forecast->periods[7]->lines);
        self::assertSame(['Retainer'], $this->labels($forecast->periods[8]->lines));
        self::assertSame(9000000 + 11 * 5000000, $forecast->periods[16]->closing->minor());
        self::assertSame(9000000, $forecast->lowest->minor());
        self::assertSame('2026-11-20', $forecast->lowestOn->format('Y-m-d'));
        self::assertNull($forecast->minimum);
        self::assertNull($forecast->shortfallOn);
        self::assertSame([], $forecast->unavailableSources);
        self::assertSame([], $forecast->unavailableRates);
    }

    public function testAsksEverySourceFromTodayToTheEndOfTheHorizonInTheTenantsZone(): void
    {
        $source = new FakeSource('test.source');

        $this->forecast(sources: [$source], months: 3);

        self::assertSame([[$this->firm->id(), '2026-11-17', '2027-01-31', '2026-11-17 Europe/Prague']], $source->asked);
    }

    public function testABalanceOlderThanSevenDaysIsStale(): void
    {
        $this->checkpoint('2026-11-10', '1000.00');
        self::assertFalse($this->forecast()->stale);

        $this->checkpoint('2026-11-09', '2000.00', $this->euroAccount());
        $forecast = $this->forecast();

        // The latest of each account counts; the oldest of them decides.
        self::assertTrue($forecast->stale);
        self::assertSame('2026-11-09', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertSame(100000 + 4900000, $forecast->opening->minor());
    }

    public function testTheLatestCheckpointOfAnAccountCountsAndAFutureOneIsNotThere(): void
    {
        $this->checkpoint('2026-11-01', '1000.00');
        $this->checkpoint('2026-11-09', '3000.00');

        $forecast = $this->forecast();

        self::assertSame(300000, $forecast->opening->minor());
        self::assertSame('2026-11-09', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertTrue($forecast->stale);
        self::assertSame(300000, $forecast->accounts[0]->balance?->minor());
        self::assertNull($forecast->accounts[0]->source);
    }

    public function testAnAccountWithoutACheckpointMakesItIncomplete(): void
    {
        $forecast = $this->forecast();

        self::assertSame(0, $forecast->opening->minor());
        self::assertNull($forecast->balanceAsOf);
        self::assertTrue($forecast->stale);
        self::assertTrue($forecast->isIncomplete());
        self::assertSame($this->account->id(), $forecast->accounts[0]->account->id());
        self::assertNull($forecast->accounts[0]->balance);
        self::assertNull($forecast->accounts[0]->amount);
        self::assertNull($forecast->accounts[0]->asOf);
    }

    public function testOverdueDocumentsAreCountedFirstAndAnExpectedDayMovesOnlyADocument(): void
    {
        $this->checkpoint('2026-11-17', '0.00');
        $source = new FakeSource('test.invoices', [
            $this->flow('inv-late', '2026-11-10', '5000.00'),
            $this->flow('inv-promised', '2026-11-12', '7000.00'),
            $this->flow('plan', '2026-11-25', '100.00', certainty: FlowCertainty::Plan),
            $this->flow('inv-far', '2027-11-01', '1.00'),
            $this->flow('inv-today', '2026-11-17', '300.00', direction: FlowDirection::Out, flags: [FlowFlag::NoDueDate]),
        ]);
        $this->expect('invoice', 'inv-promised', '2026-12-15');
        $this->expect('invoice', 'plan', '2026-12-15');

        $forecast = $this->forecast(sources: [$source]);

        $first = $forecast->periods[0];
        self::assertSame(['inv-late', 'inv-today'], $this->ids($first->lines));
        $late = $first->lines[0];
        self::assertSame([FlowFlag::Overdue], $late->flow->flags);
        self::assertSame('2026-11-10', $late->flow->expectedOn->format('Y-m-d'));
        self::assertSame('2026-11-17', $late->countedOn->format('Y-m-d'));
        self::assertSame([FlowFlag::NoDueDate], $first->lines[1]->flow->flags);
        self::assertSame(['plan'], $this->ids($forecast->periods[1]->lines));
        $promised = $forecast->periods[4]->lines[0];
        self::assertSame('inv-promised', $promised->flow->subject->id);
        self::assertSame([FlowFlag::ExpectedDate], $promised->flow->flags);
        self::assertSame('2026-12-15', $promised->countedOn->format('Y-m-d'));
        self::assertSame(1, array_sum(array_map(static fn (\App\CashFlow\Application\PeriodForecast $period): int => \count($period->lines), $forecast->periods)) - 3, 'The flow after the horizon is left out.');
    }

    public function testAnOverdueDocumentMovedToAPastDayIsStillOverdue(): void
    {
        $this->expect('invoice', 'inv-late', '2026-11-15');

        $forecast = $this->forecast(sources: [new FakeSource('test.invoices', [$this->flow('inv-late', '2026-11-10', '5000.00')])]);

        self::assertSame([FlowFlag::ExpectedDate, FlowFlag::Overdue], $forecast->periods[0]->lines[0]->flow->flags);
        self::assertSame('2026-11-15', $forecast->periods[0]->lines[0]->flow->expectedOn->format('Y-m-d'));
    }

    public function testAFailingSourceIsListedAndTheOthersStay(): void
    {
        $this->checkpoint('2026-11-17', '1000.00');
        $broken = new FakeSource('test.broken', [$this->flow('half', '2026-11-20', '999.00')], fails: true);
        $good = new FakeSource('test.good', [$this->flow('whole', '2026-11-20', '10.00')]);

        $forecast = $this->forecast(sources: [$broken, $good, new FakeSource('test.broken', fails: true)]);

        self::assertSame(['test.broken'], $forecast->unavailableSources);
        self::assertTrue($forecast->isIncomplete());
        // Nothing of the failing source is counted, not even what it gave before it failed.
        self::assertSame(['whole'], $this->ids($forecast->periods[0]->lines));
        self::assertSame(101000, $forecast->periods[0]->closing->minor());
    }

    public function testABalanceSourceAddsWhatWasBookedAfterTheCheckpoint(): void
    {
        $this->checkpoint('2026-11-10', '1000.00');
        $unknowing = new FakeBalanceSource('test.unknowing');
        $broken = new FakeBalanceSource('test.broken', fails: true);
        $bank = new FakeBalanceSource('test.bank', booked: '250.00');
        $second = new FakeBalanceSource('test.second', booked: '9999.00');

        $forecast = $this->forecast(balanceSources: [$unknowing, $broken, $bank, $second]);

        self::assertSame(125000, $forecast->opening->minor());
        self::assertSame('test.bank', $forecast->accounts[0]->source);
        self::assertSame('2026-11-17', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertFalse($forecast->stale);
        self::assertSame(['test.broken'], $forecast->unavailableSources);
        self::assertSame([], $second->asked, 'The first source that knows the account wins.');
        $asked = $bank->asked[0];
        self::assertSame([$this->account->id(), $this->firm->id(), 100000, '2026-11-10'], [$asked->accountId, $asked->firmId, $asked->amount->minor(), $asked->on->format('Y-m-d')]);
    }

    public function testWithoutAKnowingBalanceSourceTheCheckpointIsTheBalance(): void
    {
        $this->checkpoint('2026-11-12', '1000.00');

        $forecast = $this->forecast(balanceSources: [new FakeBalanceSource('test.unknowing')]);

        self::assertSame(100000, $forecast->opening->minor());
        self::assertSame('2026-11-12', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertSame([], $forecast->unavailableSources);
    }

    public function testForeignAmountsAreInCzkAtTodaysRateAndKeepTheirOwn(): void
    {
        $this->checkpoint('2026-11-17', '1000.00', $this->euroAccount());
        $this->planned(amount: '100.00', currency: 'EUR');

        $forecast = $this->forecast();

        self::assertSame(2450000, $forecast->opening->minor());
        $balance = $forecast->accounts[1]->balance ?? throw new \LogicException('No balance.');
        self::assertSame(100000, $balance->minor());
        self::assertSame(Currency::EUR, $balance->currency());
        self::assertSame(2450000, $forecast->accounts[1]->amount?->minor());
        $line = $forecast->periods[0]->lines[0];
        self::assertSame(10000, $line->flow->amount->minor());
        self::assertSame(Currency::EUR, $line->flow->amount->currency());
        self::assertSame(245000, $line->amount->minor());
        self::assertSame(Currency::CZK, $line->amount->currency());
    }

    public function testARateQuotedForMoreUnitsIsDividedAndAMissingRateIsListed(): void
    {
        $this->planned(amount: '100.00', currency: 'EUR');
        $this->planned(amount: '100.00', name: 'Hosting', currency: 'USD');
        $this->planned(amount: '50.00', name: 'Domain', currency: 'USD');
        $rates = new class implements ExchangeRates {
            public int $asked = 0;

            public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
            {
                ++$this->asked;
                if ('USD' === $currency) {
                    throw new RateNotAvailable('No dollar today.');
                }

                return new ExchangeRate($currency, 100, '2450.000', $date, $date);
            }
        };

        $forecast = $this->forecast(rates: $rates);

        self::assertSame(['Office rent'], $this->labels($forecast->periods[0]->lines));
        self::assertSame(245000, $forecast->periods[0]->lines[0]->amount->minor());
        self::assertSame(['USD'], $forecast->unavailableRates);
        self::assertTrue($forecast->isIncomplete());
        self::assertSame(2, $rates->asked, 'A rate is asked once per currency.');
    }

    public function testWhatFailsIsLoggedWithItsKeyAndItsCause(): void
    {
        $this->checkpoint('2026-11-16', '1000.00');
        $logger = new SpyLogger();
        $rates = new class implements ExchangeRates {
            public int $asked = 0;

            public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
            {
                ++$this->asked;

                throw new RateNotAvailable('No rate today.');
            }
        };
        $source = new FakeSource('test.broken', fails: true);
        $foreign = new FakeSource('test.foreign', [$this->flow('inv-eur', '2026-11-20', '10.00', currency: Currency::EUR), $this->flow('inv-czk', '2026-11-21', '10.00')]);

        $forecast = $this->forecasts([$source, $foreign], [new FakeBalanceSource('test.bank', fails: true)], $rates, $logger)->of($this->firm->id());

        self::assertSame(['test.bank', 'test.broken'], $forecast->unavailableSources);
        self::assertSame(['EUR'], $forecast->unavailableRates);
        self::assertSame(1, $rates->asked, 'A CZK amount needs no rate.');
        self::assertCount(3, $logger->records);
        [$balance, $flows, $rate] = $logger->records;
        self::assertSame(['error', 'The balance source {source} failed.', ['source', 'exception']], [$balance['level'], $balance['message'], array_keys($balance['context'])]);
        self::assertSame('test.bank', $balance['context']['source']);
        self::assertInstanceOf(\RuntimeException::class, $balance['context']['exception']);
        self::assertSame(['error', 'The cash-flow source {source} failed.', ['source', 'exception']], [$flows['level'], $flows['message'], array_keys($flows['context'])]);
        self::assertSame('test.broken', $flows['context']['source']);
        self::assertInstanceOf(\RuntimeException::class, $flows['context']['exception']);
        self::assertSame(['warning', 'No rate of {currency} for the cash-flow forecast.', ['currency', 'exception']], [$rate['level'], $rate['message'], array_keys($rate['context'])]);
        self::assertSame('EUR', $rate['context']['currency']);
        self::assertInstanceOf(RateNotAvailable::class, $rate['context']['exception']);
    }

    public function testAnUnavailableSourceIsListedOnceInAList(): void
    {
        $this->checkpoint('2026-11-16', '1000.00');
        $this->checkpoint('2026-11-16', '2000.00', $this->euroAccount());

        $forecast = $this->forecast(sources: [new FakeSource('test.broken', fails: true)], balanceSources: [new FakeBalanceSource('test.bank', fails: true)]);

        self::assertSame(['test.bank', 'test.broken'], $forecast->unavailableSources);
    }

    public function testTheBalanceIsAsOldAsTheOldestKnownOneEvenWhenAnAccountHasNone(): void
    {
        $this->checkpoint('2026-11-16', '1000.00');
        $this->euroAccount();

        $forecast = $this->forecast();

        self::assertSame('2026-11-16', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertTrue($forecast->isIncomplete());
    }

    public function testTheLinesOfAPeriodAreInTheOrderTheyAreCountedNotTheOrderASourceGaveThem(): void
    {
        $source = new FakeSource('test.invoices', [$this->flow('later', '2026-11-19', '1.00'), $this->flow('sooner', '2026-11-18', '1.00')]);

        $forecast = $this->forecast(sources: [$source]);

        self::assertSame(['sooner', 'later'], $this->ids($forecast->periods[0]->lines));
    }

    public function testTheLastDayOfTheHorizonCountsAndWhatComesAfterItIsNotLookedAt(): void
    {
        $rates = new class implements ExchangeRates {
            public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
            {
                throw new RateNotAvailable('No rate today.');
            }
        };
        $source = new FakeSource('test.invoices', [$this->flow('last-day', '2026-11-30', '5.00'), $this->flow('after', '2026-12-01', '5.00', currency: Currency::EUR)]);

        $forecast = $this->forecasts([$source], [], $rates)->of($this->firm->id(), months: 1);

        self::assertSame(['last-day'], $this->ids($forecast->periods[\count($forecast->periods) - 1]->lines));
        self::assertSame([], $forecast->unavailableRates);
    }

    public function testAnOpeningEqualToTheMinimumIsNotAShortfall(): void
    {
        $this->checkpoint('2026-11-17', '5000.00');
        $this->minimum('5000.00');

        $forecast = $this->forecast();

        self::assertNull($forecast->shortfallOn);
        self::assertSame('2026-11-17', $forecast->lowestOn->format('Y-m-d'));
    }

    public function testALaterDayAsLowAsTheLowestDoesNotMoveIt(): void
    {
        $this->checkpoint('2026-11-17', '1000.00');
        $this->planned(on: '2026-11-18', amount: '100.00', direction: 'in', category: 'revenue', name: 'Client');
        $this->planned(on: '2026-11-19', amount: '100.00');

        $forecast = $this->forecast();

        self::assertSame(100000, $forecast->lowest->minor());
        self::assertSame('2026-11-17', $forecast->lowestOn->format('Y-m-d'));
    }

    public function testOnlyTheBalanceAtTheEndOfADayCanBeTheLowest(): void
    {
        $this->checkpoint('2026-11-17', '1000.00');
        // The overdue payment comes first on the day and the payment received after it.
        $source = new FakeSource('test.invoices', [
            $this->flow('inv-late', '2026-11-10', '600.00', direction: FlowDirection::Out),
            $this->flow('inv-today', '2026-11-17', '1000.00'),
        ]);

        $forecast = $this->forecast(sources: [$source]);

        self::assertSame(['inv-late', 'inv-today'], $this->ids($forecast->periods[0]->lines));
        self::assertSame(140000, $forecast->periods[0]->closing->minor());
        self::assertSame(100000, $forecast->lowest->minor());
        self::assertSame('2026-11-17', $forecast->lowestOn->format('Y-m-d'));
    }

    public function testTheLowestPointAndTheFirstDayBelowTheMinimum(): void
    {
        $this->checkpoint('2026-11-17', '20000.00');
        $this->planned(on: '2026-11-18', amount: '6000.00');
        $this->planned(on: '2026-11-18', amount: '5000.00', direction: 'in', category: 'revenue', name: 'Client');
        $this->planned(on: '2026-11-19', amount: '15000.00', name: 'Tax');
        $this->planned(on: '2026-11-20', amount: '1000.00', direction: 'in', category: 'revenue', name: 'Client');
        $this->planned(on: '2026-12-02', amount: '3000.00', category: 'software', name: 'Software');
        $this->minimum('5000.00');

        $forecast = $this->forecast();

        // At the end of each day: 19,000, 4,000, 5,000, then 2,000.
        self::assertSame(200000, $forecast->lowest->minor());
        self::assertSame('2026-12-02', $forecast->lowestOn->format('Y-m-d'));
        self::assertSame(500000, $forecast->minimum?->minor());
        self::assertSame('2026-11-19', $forecast->shortfallOn?->format('Y-m-d'));
        self::assertSame(['revenue' => 600000], $this->minors($forecast->periods[0]->inflows));
        self::assertSame(['rent' => 2100000], $this->minors($forecast->periods[0]->outflows));
    }

    public function testAnOpeningBelowTheMinimumFallsShortOnTheFirstDay(): void
    {
        $this->checkpoint('2026-11-17', '100.00');
        $this->minimum('5000.00');

        $forecast = $this->forecast();

        self::assertSame('2026-11-17', $forecast->shortfallOn?->format('Y-m-d'));
        self::assertSame('2026-11-17', $forecast->lowestOn->format('Y-m-d'));
        self::assertSame(10000, $forecast->lowest->minor());
    }

    public function testAMinimumNotReachedHasNoShortfall(): void
    {
        $this->checkpoint('2026-11-17', '5000.00');
        $this->planned(amount: '1.00');
        $this->minimum('4999.00');

        self::assertNull($this->forecast()->shortfallOn);
    }

    public function testALaterFirstDayCountsWhatComesBeforeItInTheOpening(): void
    {
        $this->checkpoint('2026-11-17', '100000.00');
        $this->planned();
        $source = new FakeSource('test.invoices', [$this->flow('inv-late', '2026-11-10', '500.00')]);

        $forecast = $this->forecast(sources: [$source, $this->plannedSource()], from: '2026-12-01', months: 2, weeksUntil: '2026-12-31');

        self::assertSame(10000000, $forecast->opening->minor());
        self::assertSame('2026-12-01', $forecast->from->format('Y-m-d'));
        $first = $forecast->periods[0];
        self::assertSame('2026-12-01', $first->period->start->format('Y-m-d'));
        self::assertSame(9000000, $first->opening->minor());
        self::assertSame(['inv-late'], $this->ids($first->lines));
        self::assertSame('2026-12-01', $first->lines[0]->countedOn->format('Y-m-d'));
        self::assertSame(9000000, $forecast->lowest->minor(), 'The opening of the first day is lower than its end.');
        self::assertSame('2026-12-01', $forecast->lowestOn->format('Y-m-d'));
        self::assertCount(6, $forecast->periods);
        self::assertSame('2027-01-31', $forecast->periods[5]->period->end->format('Y-m-d'));
    }

    public function testRefusesWhatCannotBeForecast(): void
    {
        $this->assertRefused('from_in_past', 'from', fn (): Forecast => $this->forecast(from: '2026-11-16'));
        $this->assertRefused('date_invalid', 'from', fn (): Forecast => $this->forecast(from: 'tomorrow'));
        $this->assertRefused('months_invalid', 'months', fn (): Forecast => $this->forecast(months: 0));
        $this->assertRefused('months_invalid', 'months', fn (): Forecast => $this->forecast(months: Forecasts::MAX_MONTHS + 1));
        $this->assertRefused('date_invalid', 'weeksUntil', fn (): Forecast => $this->forecast(weeksUntil: '2026-13-01'));
        $this->assertRefused('not_found', null, fn (): Forecast => $this->forecasts()->of('0198a4f0-8b1e-7000-8000-000000000001'));
        self::assertCount(24, $this->forecast(months: Forecasts::MAX_MONTHS, weeksUntil: '2026-11-16')->periods);
        self::assertCount(1, $this->forecast(months: 1, weeksUntil: '2026-11-16')->periods);
        $this->signIn($this->guest);
        $this->assertRefused('forbidden', null, fn (): Forecast => $this->forecast());
    }

    public function testInTheHourAfterPragueMidnightTodayIsThePragueDay(): void
    {
        // 23:30 UTC on 30 November is 00:30 on 1 December in Prague.
        $this->clock->modify('2026-11-30 23:30:00');
        $this->checkpoint('2026-12-01', '1000.00');
        $this->planned(on: '2026-11-30', name: 'Yesterday');
        $this->planned(on: '2026-12-01', name: 'Today');
        $source = new FakeSource('test.invoices', [$this->flow('due-yesterday', '2026-11-30', '10.00'), $this->flow('due-today', '2026-12-01', '20.00')]);

        $forecast = $this->forecast(sources: [$source, $this->plannedSource()]);

        self::assertSame('2026-12-01', $forecast->today->format('Y-m-d'));
        self::assertSame('2026-W49', $forecast->periods[0]->period->key());
        self::assertSame('2026-12-01', $forecast->periods[0]->period->start->format('Y-m-d'));
        self::assertSame(['due-yesterday', 'due-today'], $this->ids(array_values(array_filter($forecast->periods[0]->lines, static fn (ForecastLine $line): bool => 'invoice' === $line->flow->subject->type))));
        self::assertSame(['Today'], $this->labels(array_values(array_filter($forecast->periods[0]->lines, static fn (ForecastLine $line): bool => FlowCertainty::Plan === $line->flow->certainty))));
        self::assertSame([FlowFlag::Overdue], $forecast->periods[0]->lines[0]->flow->flags);
        self::assertFalse($forecast->stale);
        self::assertSame('2027-01-31', $forecast->periods[8]->period->end->format('Y-m-d'), 'The weeks run to the end of the month after December.');
    }

    public function testInTheHourBeforePragueMidnightTodayIsStillThePragueDay(): void
    {
        // 22:30 UTC on 30 November is 23:30 on 30 November in Prague.
        $this->clock->modify('2026-11-30 22:30:00');
        $this->planned(on: '2026-11-30', name: 'Today');

        $forecast = $this->forecast();

        self::assertSame('2026-11-30', $forecast->today->format('Y-m-d'));
        self::assertSame(['Today'], $this->labels($forecast->periods[0]->lines));
    }

    /**
     * @param list<CashFlowSource>|null $sources        the planned items when null
     * @param list<BalanceSource>       $balanceSources
     */
    private function forecast(?array $sources = null, array $balanceSources = [], ?string $from = null, ?int $months = null, ?string $weeksUntil = null, ?ExchangeRates $rates = null): Forecast
    {
        return $this->forecasts($sources ?? [$this->plannedSource()], $balanceSources, $rates)->of($this->firm->id(), $from, $months, $weeksUntil);
    }

    /**
     * @param list<CashFlowSource> $sources
     * @param list<BalanceSource>  $balanceSources
     */
    private function forecasts(array $sources = [], array $balanceSources = [], ?ExchangeRates $rates = null, ?LoggerInterface $logger = null): Forecasts
    {
        $container = self::getContainer();

        return new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(TenantClock::class), $rates ?? $container->get(ExchangeRates::class), $logger ?? new NullLogger(), $sources, $balanceSources);
    }

    private function plannedSource(): PlannedItemFlows
    {
        return new PlannedItemFlows(self::getContainer()->get(CashFlowStore::class));
    }

    /**
     * @param list<FlowFlag> $flags
     */
    private function flow(string $id, string $on, string $amount, FlowCertainty $certainty = FlowCertainty::Document, FlowDirection $direction = FlowDirection::In, array $flags = [], Currency $currency = Currency::CZK): ExpectedFlow
    {
        return new ExpectedFlow('test.invoices', new FlowSubject('invoice', $id, '/api/v1/invoices/'.$id), $direction, Money::of($amount, $currency), new \DateTimeImmutable($on, new \DateTimeZone('Europe/Prague')), FlowCategory::Revenue, $certainty, 'Invoice '.$id, $flags);
    }

    private function checkpoint(string $on, string $amount, ?\App\Party\Domain\FirmBankAccount $account = null): void
    {
        self::getContainer()->get(BalanceWrites::class)->checkpoint(new RecordCheckpointInput(($account ?? $this->account)->id(), $on, $amount));
    }

    private function expect(string $type, string $id, string $on): void
    {
        self::getContainer()->get(BalanceWrites::class)->expect(new SetExpectedDateInput($type, $id, $on));
    }

    private function minimum(?string $amount): void
    {
        self::getContainer()->get(BalanceWrites::class)->minimum(new SetMinimumBalanceInput($this->firm->id(), $amount));
    }

    /**
     * @param array<string, Money> $amounts
     *
     * @return array<string, int>
     */
    private function minors(array $amounts): array
    {
        return array_map(static fn (Money $amount): int => $amount->minor(), $amounts);
    }

    /**
     * @param list<ForecastLine> $lines
     *
     * @return list<string>
     */
    private function labels(array $lines): array
    {
        return array_map(static fn (ForecastLine $line): string => $line->flow->label, $lines);
    }

    /**
     * @param list<ForecastLine> $lines
     *
     * @return list<string>
     */
    private function ids(array $lines): array
    {
        return array_map(static fn (ForecastLine $line): string => $line->flow->subject->id, $lines);
    }

    private function assertRefused(string $reason, ?string $field, \Closure $work): void
    {
        try {
            $work();
            self::fail(\sprintf('Expected the refusal %s.', $reason));
        } catch (CashFlowRefused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());
            self::assertSame($field, $refused->field());
            self::assertSame(match ($reason) {
                'not_found' => RefusalKind::NotFound,
                'forbidden' => RefusalKind::Forbidden,
                default => RefusalKind::Invalid,
            }, $refused->kind());
        }
    }
}
