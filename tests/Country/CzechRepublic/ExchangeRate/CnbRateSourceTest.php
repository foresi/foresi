<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\ExchangeRate;

use App\Country\CzechRepublic\ExchangeRate\CnbRateSource;
use App\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CnbRateSourceTest extends TestCase
{
    public function testTheDateIsAskedInTheFormatOfTheBank(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('cnb-2026-11-10.txt'));

        $published = new CnbRateSource($kit->client)->fetch(new \DateTimeImmutable('2026-11-10'));

        self::assertNotNull($published);
        self::assertSame('2026-11-10', $published->publishedOn->format('Y-m-d'));
        self::assertSame('GET', $kit->requests[0]['method']);
        self::assertSame(CnbRateSource::URL.'?date=10.11.2026', $kit->requests[0]['url']);
        self::assertSame(5.0, $kit->requests[0]['timeout']);
    }

    public function testASaturdayIsAnsweredWithTheFridayFile(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => self::fixture('cnb-2026-11-06.txt'));

        $published = new CnbRateSource($kit->client)->fetch(new \DateTimeImmutable('2026-11-07'));

        self::assertNotNull($published);
        self::assertSame('2026-11-06', $published->publishedOn->format('Y-m-d'));
        self::assertStringEndsWith('?date=07.11.2026', $kit->requests[0]['url']);
    }

    public function testAServerErrorIsNullWithAWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 503]));

        self::assertNull(new CnbRateSource($kit->client)->fetch(new \DateTimeImmutable('2026-11-10')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testAPageThatIsNotTheFileIsNullWithAWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('<html>Maintenance</html>'));

        self::assertNull(new CnbRateSource($kit->client)->fetch(new \DateTimeImmutable('2026-11-10')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testTheTimesAreThoseOfPragueAfterThePublication(): void
    {
        $source = new CnbRateSource(new RegistryTestKit(static fn (): MockResponse => new MockResponse(''))->client);

        self::assertSame('Europe/Prague', $source->zone()->getName());
        self::assertSame('14:35', $source->expectedAt());
        self::assertSame('15:00', $source->settledAt());
        self::assertSame('cnb', $source->name());
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse(RegistryTestKit::fixtureIn('ExchangeRate', $name), ['http_code' => 200, 'response_headers' => ['content-type: text/plain;charset=UTF-8']]);
    }
}
