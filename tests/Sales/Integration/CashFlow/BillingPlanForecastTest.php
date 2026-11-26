<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\CashFlow;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\ForecastLine;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\PeriodForecast;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\CashFlow\Application\UndatedLine;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\CashFlow\Infrastructure\Screen\ForecastScreen;
use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\CreateDealStageInput;
use Foresi\Sales\Application\Api\UpdateBillingEntryInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Sales\Application\DealLineWrites;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\DealWrites;
use Foresi\Sales\Application\StageWrites;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Integration\CashFlow\BillingPlanFlows;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * A won deal in the cash-flow forecast (D1): without a day it is in the No
 * date column and in no balance; with a day it moves into its period, its
 * money due the client's due days later, with the certainty deal.
 */
final class BillingPlanForecastTest extends CashFlowTestCase
{
    public function testAWonDealWithoutADayIsOutsideTheBalanceUntilItGetsOne(): void
    {
        self::getContainer()->get(BalanceWrites::class)->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-17', '50000.00'));
        // The Skácel case: won, one one-off line of 10,000 CZK, nothing invoiced.
        $deal = $this->wonDeal('Initial e-shop audit', '10000.00');

        $forecast = $this->forecast();
        self::assertSame([[BillingPlanFlows::KEY, 'Initial e-shop audit', 1_000_000, FlowCertainty::Deal]], array_map(
            static fn (UndatedLine $line): array => [$line->flow->source, $line->flow->label, $line->amount->minor(), $line->flow->certainty],
            $forecast->undated->lines,
        ));
        self::assertSame(['revenue' => 1_000_000], array_map(static fn ($money): int => $money->minor(), $forecast->undated->inflows));
        // Nothing of it in a period, a balance or the lowest point.
        self::assertSame([], $this->dealLines($forecast));
        self::assertSame(5_000_000, $forecast->periods[array_key_last($forecast->periods)]->closing->minor());
        self::assertSame(5_000_000, $forecast->lowest->minor());
        // Nor in what other modules read, such as the taxes forecast.
        $read = self::getContainer()->get(ForecastReader::class)->read(new ForecastQuery(new \DateTimeImmutable('2026-11-17'), new \DateTimeImmutable('2027-11-16')));
        self::assertSame([], array_values(array_filter($read->flows, static fn ($flow): bool => BillingPlanFlows::KEY === $flow->source)));

        // The page: a No date column after the last period, with its own total, and a tile.
        $screen = self::getContainer()->get(ForecastScreen::class);
        $table = $screen->table($forecast);
        $last = $table['columns'][array_key_last($table['columns'])];
        self::assertSame([ForecastScreen::UNDATED, true], [$last['key'], $last['undated']]);
        $in = array_values(array_filter($table['rows'], static fn (array $row): bool => 'total' === $row['kind']))[0];
        self::assertStringContainsString('10', $in['cells'][array_key_last($in['cells'])]['text']);
        self::assertNotNull($in['cells'][array_key_last($in['cells'])]['href']);
        $tiles = $screen->kpis($forecast);
        self::assertCount(5, $tiles);
        self::assertNotNull($tiles[4]['href'] ?? null);
        $items = $screen->undatedItems($forecast, \Foresi\CashFlow\Contract\FlowDirection::In, null);
        self::assertSame('Initial e-shop audit', $items['items'][0]['label']);
        self::assertNotNull($items['items'][0]['expected'], 'The item gets its day where it is planned.');
        // And the API.
        $json = CashFlowJson::forecast($forecast, $this->firm);
        self::assertSame('deal', $json['undated']['items'][0]['certainty']);
        self::assertSame(1_000_000, $json['undated']['inflows']['total']['amount_minor']);

        // A day moves it into its period: invoiced on 1 December, paid 14 days later.
        $entry = self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan->entries[0]->entry;
        self::getContainer()->get(BillingPlanWrites::class)->update(new UpdateBillingEntryInput($deal->id(), $entry->id(), expectedOn: '2026-12-01'));

        $forecast = $this->forecast();
        self::assertTrue($forecast->undated->isEmpty());
        self::assertSame([['2026-12-15', 1_000_000, FlowCertainty::Deal, 'Initial e-shop audit']], array_map(
            static fn (ForecastLine $line): array => [$line->countedOn->format('Y-m-d'), $line->amount->minor(), $line->flow->certainty, $line->flow->label],
            $this->dealLines($forecast),
        ));
        self::assertSame(6_000_000, $forecast->periods[array_key_last($forecast->periods)]->closing->minor());

        // The certainty filter shows or hides it.
        self::assertSame([], $this->dealLines($this->forecast([FlowCertainty::Document])));
    }

    public function testARecurringEntryFollowsItsLine(): void
    {
        $deal = $this->deal('Ongoing SEO');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'SEO', unitPrice: '14000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1, cycles: 3));
        $this->deals()->win(new WinDealInput($deal->id()));
        $entry = self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan->entries[0]->entry;
        // Without a day its first invoice waits under No date.
        self::assertSame([1_400_000], array_map(static fn (UndatedLine $line): int => $line->amount->minor(), $this->forecast()->undated->lines));

        self::getContainer()->get(BillingPlanWrites::class)->update(new UpdateBillingEntryInput($deal->id(), $entry->id(), expectedOn: '2026-12-01'));

        self::assertSame(['2026-12-15', '2027-01-15', '2027-02-15'], array_map(static fn (ForecastLine $line): string => $line->countedOn->format('Y-m-d'), $this->dealLines($this->forecast())), 'Three cycles, each paid 14 days after its invoice.');
    }

    /**
     * @param list<FlowCertainty>|null $certainties
     */
    private function forecast(?array $certainties = null): Forecast
    {
        return self::getContainer()->get(Forecasts::class)->of(certainties: $certainties);
    }

    /**
     * @return list<ForecastLine>
     */
    private function dealLines(Forecast $forecast): array
    {
        return array_values(array_filter(array_merge(...array_map(static fn (PeriodForecast $period): array => $period->lines, $forecast->periods)), static fn (ForecastLine $line): bool => BillingPlanFlows::KEY === $line->flow->source));
    }

    private function wonDeal(string $title, string $price): Deal
    {
        $deal = $this->deal($title);
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Audit', unit: 'piece', quantity: '1', unitPrice: $price, currency: 'CZK'));

        return $this->deals()->win(new WinDealInput($deal->id()));
    }

    private function deal(string $title): Deal
    {
        $stage = self::getContainer()->get(StageWrites::class)->create(new CreateDealStageInput('Inquiry', 'inquiry_'.substr(md5($title), 0, 6)));

        return $this->deals()->create(new CreateDealInput($title, $this->customer->id(), $stage->id()));
    }

    private function deals(): DealWrites
    {
        return self::getContainer()->get(DealWrites::class);
    }

    private function lines(): DealLineWrites
    {
        return self::getContainer()->get(DealLineWrites::class);
    }
}
