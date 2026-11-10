<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Application;

use App\ExchangeRate\Domain\RateNotAvailable;
use App\Tests\ExchangeRate\Support\RateWorld;
use PHPUnit\Framework\TestCase;

final class StoredExchangeRatesTest extends TestCase
{
    public function testARateOfABusinessDayIsFetchedOnDemandAndStored(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        $rate = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));

        self::assertSame('EUR', $rate->currency);
        self::assertSame(1, $rate->amount);
        self::assertSame('24.335', $rate->rate);
        self::assertSame('2026-11-10', $rate->validOn->format('Y-m-d'));
        self::assertSame('2026-11-10', $rate->publishedOn->format('Y-m-d'));
        self::assertSame(2, $world->rows());
        self::assertSame(['2026-11-10'], $world->source->asked);
    }

    public function testAStoredDateIsNotFetchedAgain(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));
        $again = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));
        $other = $world->rates->rateOn('JPY', new \DateTimeImmutable('2026-11-10'));

        self::assertSame('24.335', $again->rate);
        self::assertSame('14.981', $other->rate);
        self::assertSame(100, $other->amount);
        self::assertCount(1, $world->source->asked);
    }

    public function testASaturdayAndASundayHoldTheFridayRate(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        $saturday = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-07'));
        $sunday = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-08'));

        self::assertSame('24.3', $saturday->rate);
        self::assertSame('2026-11-07', $saturday->validOn->format('Y-m-d'));
        self::assertSame('2026-11-06', $saturday->publishedOn->format('Y-m-d'));
        self::assertSame('24.3', $sunday->rate);
        self::assertSame('2026-11-06', $sunday->publishedOn->format('Y-m-d'));
    }

    public function testAHolidayHoldsTheLastPublicationBeforeIt(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        $holiday = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-09'));

        self::assertSame('24.3', $holiday->rate);
        self::assertSame('2026-11-06', $holiday->publishedOn->format('Y-m-d'));
        // Kept for the date asked, so the next ask is a read.
        $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-09'));
        self::assertCount(1, $world->source->asked);
    }

    public function testADateBeforeTodaysPublicationUsesThePreviousOne(): void
    {
        $world = new RateWorld('2026-11-10 09:00:00');

        $rate = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));

        self::assertSame('24.3', $rate->rate);
        self::assertSame('2026-11-06', $rate->publishedOn->format('Y-m-d'));
        self::assertSame('2026-11-10', $rate->validOn->format('Y-m-d'));
    }

    public function testTheRateBeforeThePublicationIsNotKeptForTodayAndTheRealOneReplacesIt(): void
    {
        $world = new RateWorld('2026-11-10 09:00:00');
        $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));

        $world->clock->modify('2026-11-10 14:40:00');
        $afterwards = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));

        self::assertSame('24.335', $afterwards->rate);
        self::assertSame('2026-11-10', $afterwards->publishedOn->format('Y-m-d'));
    }

    public function testAnEveningWithoutAPublicationKeepsThePreviousRateForToday(): void
    {
        // Wednesday 11 November is a holiday in this world: nothing published.
        $world = new RateWorld('2026-11-11 16:00:00');

        $rate = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-11'));
        $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-11'));

        self::assertSame('24.335', $rate->rate);
        self::assertSame('2026-11-10', $rate->publishedOn->format('Y-m-d'));
        self::assertCount(1, $world->source->asked);
    }

    public function testAFutureDateHasTodaysRate(): void
    {
        $world = new RateWorld('2026-11-10 16:00:00');

        $rate = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-12-24'));

        self::assertSame('2026-11-10', $rate->validOn->format('Y-m-d'));
        self::assertSame('24.335', $rate->rate);
        self::assertSame(['2026-11-10'], $world->source->asked);
    }

    public function testTheCzechCrownIsOneForOneAndNeverFetched(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        $rate = $world->rates->rateOn('CZK', new \DateTimeImmutable('2026-11-10'));

        self::assertSame(1, $rate->amount);
        self::assertSame('1', $rate->rate);
        self::assertSame([], $world->source->asked);
    }

    public function testACurrencyTheSourceDoesNotQuoteIsNotAvailableAndNotAskedAgain(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');

        foreach ([1, 2] as $_) {
            try {
                $world->rates->rateOn('XXX', new \DateTimeImmutable('2026-11-10'));
                self::fail('A currency nobody quotes has no rate.');
            } catch (RateNotAvailable $e) {
                self::assertStringContainsString('XXX', $e->getMessage());
            }
        }

        self::assertCount(1, $world->source->asked);
    }

    public function testASourceThatIsDownMeansNoRateAndNothingStored(): void
    {
        $world = new RateWorld('2026-11-12 10:00:00');
        $world->source->down = true;

        try {
            $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'));
            self::fail('Nothing is stored and the source is down.');
        } catch (RateNotAvailable) {
            self::assertSame(0, $world->rows());
        }

        $world->source->down = false;
        self::assertSame('24.335', $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-10'))->rate);
    }

    public function testTheDateOfTheCallIsReadInPrague(): void
    {
        // 23:30 UTC on the 9th is half past midnight of the 10th in Prague.
        $world = new RateWorld('2026-11-09 23:30:00 UTC');

        $rate = $world->rates->rateOn('EUR', new \DateTimeImmutable('2026-11-20'));

        self::assertSame('2026-11-10', $rate->validOn->format('Y-m-d'));
    }
}
