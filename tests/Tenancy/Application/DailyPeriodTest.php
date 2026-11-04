<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Application;

use App\Tenancy\Application\Schedule\DailyPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyPeriodTest extends TestCase
{
    private const string PRAGUE = 'Europe/Prague';

    public function testBeforeTheStartHourIsYesterday(): void
    {
        self::assertSame('2026-12-31', $this->of('2026-12-31T23:59:00Z', self::PRAGUE, 5));
        self::assertSame('2026-12-31', $this->of('2027-01-01T03:59:00Z', self::PRAGUE, 5));
        self::assertSame('2027-01-01', $this->of('2027-01-01T04:00:00Z', self::PRAGUE, 5));
    }

    public function testDstChangeDaysKeepTheLocalHour(): void
    {
        // Prague moves to summer time at 01:00 UTC that day: 02:59 UTC is 04:59 there.
        self::assertSame('2027-03-27', $this->of('2027-03-28T02:59:00Z', self::PRAGUE, 5));
        self::assertSame('2027-03-28', $this->of('2027-03-28T03:00:00Z', self::PRAGUE, 5));
        // And back to winter time at 01:00 UTC on the last Sunday of October.
        self::assertSame('2027-10-30', $this->of('2027-10-31T03:59:00Z', self::PRAGUE, 5));
        self::assertSame('2027-10-31', $this->of('2027-10-31T04:00:00Z', self::PRAGUE, 5));
    }

    public function testTheZoneOfNowDoesNotMatter(): void
    {
        $now = new \DateTimeImmutable('2027-01-01 04:30:00', new \DateTimeZone('America/New_York'));

        self::assertSame('2027-01-01', DailyPeriod::of($now, new \DateTimeZone(self::PRAGUE), 5));
        self::assertSame('2026-12-31', DailyPeriod::of($now, new \DateTimeZone('America/New_York'), 5));
    }

    public function testAMidnightStartIsTheCalendarDay(): void
    {
        self::assertSame('2027-01-01', $this->of('2026-12-31T23:00:00Z', self::PRAGUE, 0));
        self::assertSame('2026-12-31', $this->of('2026-12-31T22:59:59Z', self::PRAGUE, 0));
    }

    public function testTheLastHourOfTheDayMayStartIt(): void
    {
        self::assertSame('2026-12-31', $this->of('2027-01-01T21:59:00Z', self::PRAGUE, 23));
        self::assertSame('2027-01-01', $this->of('2027-01-01T22:00:00Z', self::PRAGUE, 23));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'a day' => [24];
    }

    #[DataProvider('outOfRange')]
    public function testTheStartHourIsAnHourOfTheDay(int $hour): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->of('2027-01-01T04:00:00Z', self::PRAGUE, $hour);
    }

    private function of(string $now, string $zone, int $startHour): string
    {
        return DailyPeriod::of(new \DateTimeImmutable($now), new \DateTimeZone($zone), $startHour);
    }
}
