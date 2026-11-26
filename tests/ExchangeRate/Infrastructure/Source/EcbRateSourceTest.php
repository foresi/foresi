<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Infrastructure\Source;

use Foresi\ExchangeRate\Infrastructure\Source\EcbRateSource;
use Foresi\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The ECB over the public registry client: today from the daily file, an
 * earlier date from the 90-day history, each the last publication on or
 * before the date.
 */
final class EcbRateSourceTest extends TestCase
{
    public function testTodayIsReadFromTheDailyFile(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('ecb-daily-2026-11-25.xml'));

        $published = $this->source($kit, '2026-11-25 17:00:00')->fetch(new \DateTimeImmutable('2026-11-25'));

        self::assertNotNull($published);
        self::assertSame(['2026-11-25', 'EUR'], [$published->publishedOn->format('Y-m-d'), $published->base]);
        self::assertSame('GET', $kit->requests[0]['method']);
        self::assertSame(EcbRateSource::DAILY_URL, $kit->requests[0]['url']);
        self::assertSame(5.0, $kit->requests[0]['timeout']);
    }

    public function testAnEarlierDateIsReadFromTheHistoryAndAWeekendHoldsFriday(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('ecb-hist-90d-2026-11-25.xml'));
        $source = $this->source($kit, '2026-11-25 17:00:00');

        $friday = $source->fetch(new \DateTimeImmutable('2026-11-20'));
        $sunday = $source->fetch(new \DateTimeImmutable('2026-11-22'));

        self::assertSame('2026-11-20', $friday?->publishedOn->format('Y-m-d'));
        self::assertSame('2026-11-20', $sunday?->publishedOn->format('Y-m-d'));
        self::assertSame([EcbRateSource::HISTORY_URL, EcbRateSource::HISTORY_URL], array_column($kit->requests, 'url'));
    }

    public function testTheMorningOfADayIsAnsweredWithTheDayBefore(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('ecb-daily-2026-11-25.xml'));

        // Before 16:00 the daily file still holds the day before.
        $published = $this->source($kit, '2026-11-26 09:00:00')->fetch(new \DateTimeImmutable('2026-11-26'));

        self::assertSame('2026-11-25', $published?->publishedOn->format('Y-m-d'));
        self::assertSame(EcbRateSource::DAILY_URL, $kit->requests[0]['url']);
    }

    public function testADateBeforeTheHistoryHasNoRateWithAWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('ecb-hist-90d-2026-11-25.xml'));

        self::assertNull($this->source($kit, '2026-11-25 17:00:00')->fetch(new \DateTimeImmutable('2026-11-16')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testAServerErrorIsNullWithAWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 503]));

        self::assertNull($this->source($kit, '2026-11-25 17:00:00')->fetch(new \DateTimeImmutable('2026-11-25')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testAPageThatIsNotTheFileIsNullWithAWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('<html>Maintenance</html>'));

        self::assertNull($this->source($kit, '2026-11-25 17:00:00')->fetch(new \DateTimeImmutable('2026-11-25')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testTheTimesAreThoseOfFrankfurtAfterThePublication(): void
    {
        $source = $this->source(new RegistryTestKit(static fn (): MockResponse => new MockResponse('')), '2026-11-25 10:00:00');

        self::assertSame(['ecb', 'Europe/Berlin', '16:10', '17:00'], [$source->name(), $source->zone()->getName(), $source->expectedAt(), $source->settledAt()]);
    }

    private function source(RegistryTestKit $kit, string $now): EcbRateSource
    {
        return new EcbRateSource($kit->client, new MockClock($now, 'Europe/Berlin'));
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse(RegistryTestKit::fixtureIn('ExchangeRate', $name), ['http_code' => 200, 'response_headers' => ['content-type: text/xml']]);
    }
}
