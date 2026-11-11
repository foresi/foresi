<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Application;

use App\ExchangeRate\Infrastructure\Schedule\FetchDailyRates;
use App\Tests\ExchangeRate\Support\RateWorld;
use PHPUnit\Framework\TestCase;

final class FetchDailyRatesTest extends TestCase
{
    public function testItTicksEveryTenMinutesUnderItsName(): void
    {
        $task = new RateWorld('2026-11-10 12:00:00')->daily;

        self::assertSame('10 minutes', $task->every());
        self::assertSame(FetchDailyRates::NAME, $task->name());
    }

    public function testItWaitsForTheTimeOfThePublication(): void
    {
        $world = new RateWorld('2026-11-10 14:34:00');

        $world->daily->run();

        self::assertSame([], $world->source->asked);
        self::assertSame(0, $world->rows());
    }

    public function testItFetchesTodayAfterTheTimeOfThePublicationInPrague(): void
    {
        // 13:36 UTC is 14:36 in Prague in November.
        $world = new RateWorld('2026-11-10 13:36:00 UTC');

        $world->daily->run();

        self::assertSame(['2026-11-10'], $world->source->asked);
        self::assertSame('24.335', $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'))->rate);
        self::assertSame(['2026-11-10'], $world->source->asked);
    }

    public function testItDoesNotFetchADayThatHasRates(): void
    {
        $world = new RateWorld('2026-11-10 14:40:00');

        $world->daily->run();
        $world->clock->sleep(600);
        $world->daily->run();

        self::assertCount(1, $world->source->asked);
    }

    public function testItAsksAgainWhenTheBankIsLateAndTakesTheRateOnceItIsOut(): void
    {
        $world = new RateWorld('2026-11-10 14:40:00');
        $world->source->down = true;
        $world->daily->run();
        self::assertSame(0, $world->rows());

        $world->source->down = false;
        $world->clock->sleep(600);
        $world->daily->run();

        self::assertCount(2, $world->source->asked);
        self::assertSame(2, $world->rows());
    }

    public function testADayWithoutAPublicationIsAskedUntilItIsSettledAndThenLeftAlone(): void
    {
        // A holiday: the bank answers with the previous day's file.
        $world = new RateWorld('2026-11-11 14:40:00');

        foreach (range(1, 12) as $_) {
            $world->daily->run();
            $world->clock->sleep(600);
        }

        // 14:40, 14:50, then the copy is kept at 15:00 and no more asks follow.
        self::assertCount(3, $world->source->asked);
    }
}
