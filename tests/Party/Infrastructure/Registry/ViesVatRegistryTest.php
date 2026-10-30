<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Registry;

use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Registry\ViesVatRegistry;
use App\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ViesVatRegistryTest extends TestCase
{
    public function testValidVatId(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('vies-valid.json'));

        $check = new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'));

        self::assertSame(RegistrySource::Vies, $check->source);
        self::assertSame(RegistryResult::Valid, $check->result);
        self::assertEquals($kit->clock->now(), $check->checkedAt);
        self::assertSame('POST', $kit->requests[0]['method']);
        self::assertSame('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', $kit->requests[0]['url']);
        self::assertSame('{"countryCode":"CZ","vatNumber":"12345679"}', $kit->requests[0]['body']);
        self::assertSame(5.0, $kit->requests[0]['timeout']);
        self::assertContains('User-Agent: Foresi (+https://foresi.app)', $kit->requests[0]['headers']);
    }

    public function testInvalidVatId(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('vies-invalid.json'));

        self::assertSame(RegistryResult::Invalid, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'))->result);
    }

    public function testMemberStateOutageIsUnavailableAndNotCached(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('vies-unavailable.json'));
        $vies = new ViesVatRegistry($kit->client, $kit->cache, $kit->clock);

        self::assertSame(RegistryResult::Unavailable, $vies->checkVatId(VatId::fromString('CZ12345679'))->result);
        self::assertSame(RegistryResult::Unavailable, $vies->checkVatId(VatId::fromString('CZ12345679'))->result);
        self::assertCount(2, $kit->requests);
    }

    public function testServerErrorAndTimeoutAreUnavailableWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));
        self::assertSame(RegistryResult::Unavailable, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'))->result);
        self::assertSame(1, $kit->logger->warnings());

        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        self::assertSame(RegistryResult::Unavailable, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'))->result);
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testClientErrorIsUnavailable(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('{"valid":true}', ['http_code' => 400]));

        self::assertSame(RegistryResult::Unavailable, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'))->result);
    }

    public function testAnswerWithoutAVerdictIsUnavailable(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('{"userError":"MS_MAX_CONCURRENT_REQ","valid":false}', ['http_code' => 200]));

        self::assertSame(RegistryResult::Unavailable, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345679'))->result);
        self::assertSame(RegistryResult::Unavailable, new ViesVatRegistry($kit->client, $kit->cache, $kit->clock)->checkVatId(VatId::fromString('CZ12345687'))->result);
    }

    public function testCachedVerdictKeepsTheTimeItWasChecked(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('vies-valid.json'));
        $vies = new ViesVatRegistry($kit->client, $kit->cache, $kit->clock);
        $first = $vies->checkVatId(VatId::fromString('CZ12345679'));

        $kit->clock->modify('+2 hours');
        $second = $vies->checkVatId(VatId::fromString('cz 12345679'));

        self::assertCount(1, $kit->requests);
        self::assertSame(RegistryResult::Valid, $second->result);
        self::assertEquals($first->checkedAt, $second->checkedAt);
    }

    public function testCacheExpiresAfterTwentyFourHours(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('vies-valid.json'));
        $vies = new ViesVatRegistry($kit->client, $kit->cache, $kit->clock);
        $vies->checkVatId(VatId::fromString('CZ12345679'));

        $item = $kit->pool->getItem('vies.'.sha1('CZ12345679'));
        self::assertTrue($item->isHit());
        self::assertEqualsWithDelta(microtime(true) + 86400, $item->getMetadata()[CacheItem::METADATA_EXPIRY], 5);
    }
}
