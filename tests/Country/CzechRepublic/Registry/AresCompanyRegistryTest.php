<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Registry;

use App\Country\CzechRepublic\Registry\AresCompanyRegistry;
use App\Party\Domain\RegistrationNumber;
use App\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AresCompanyRegistryTest extends TestCase
{
    public function testLookupMapsNameNumberVatIdAndSeat(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-subject.json'));

        $record = new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679'));

        self::assertNotNull($record);
        self::assertSame('Example Company s.r.o.', $record->name);
        self::assertSame('12345679', $record->registrationNumber->value);
        self::assertSame('CZ12345679', $record->vatId?->toString());
        self::assertSame('Příkladná 1444/2a', $record->seat?->street);
        self::assertSame('Ostrava', $record->seat->city);
        self::assertSame('702 00', $record->seat->postalCode);
        self::assertSame('CZ', $record->seat->countryCode);
        self::assertSame('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345679', $kit->requests[0]['url']);
    }

    public function testUnknownNumberIsNullWithoutWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-not-found.json', 404));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679')));
        self::assertSame(0, $kit->logger->warnings());
    }

    public function testUnknownNumberIsCachedSoTheRegisterIsNotAskedAgain(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-not-found.json', 404));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        $ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '1234 5679'));
        $ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679'));

        self::assertCount(1, $kit->requests);
    }

    public function testServerErrorIsNullWithLoggedWarningAndNotCached(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 503]));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679')));
        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679')));

        self::assertSame(2, $kit->logger->warnings());
        self::assertCount(2, $kit->requests);
    }

    public function testTransportFailureIsNullWithLoggedWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection timed out']));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testNonJsonAnswerIsNullWithLoggedWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('<html>maintenance</html>'));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testOtherCountriesAreNotAskedAbout(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-subject.json'));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('SK', '12345679')));
        self::assertSame([], $ares->searchByName('SK', 'Example', 10));
        self::assertSame([], $kit->requests);
    }

    public function testRequestsUseTimeoutAndIdentifyingUserAgentWithoutPersonalData(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-subject.json'));

        new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345679'));

        $request = $kit->requests[0];
        self::assertSame(5.0, $request['timeout']);
        self::assertSame(5.0, $request['max_duration']);
        self::assertContains('User-Agent: Foresi (+https://foresi.app)', $request['headers']);
        self::assertContains('Accept: application/json', $request['headers']);
    }

    public function testSearchByNameMapsAllSubjectsIncludingStreetlessVillageSeat(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-search.json'));

        $records = new AresCompanyRegistry($kit->client, $kit->cache)->searchByName('cz', 'Example', 10);

        self::assertCount(3, $records);
        self::assertSame(['Example Company s.r.o.', 'Example Trading a.s.', 'Example Village Farm'], array_map(static fn (\App\Party\Application\RegistryRecord $r): string => $r->name, $records));
        self::assertSame('Ukázková 7', $records[1]->seat?->street);
        self::assertNull($records[2]->vatId);
        self::assertSame('Vzorov 15', $records[2]->seat?->street);
        self::assertSame('742 01', $records[2]->seat->postalCode);
        self::assertSame('POST', $kit->requests[0]['method']);
        self::assertSame('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat', $kit->requests[0]['url']);
        self::assertStringContainsString('"obchodniJmeno":"Example"', $kit->requests[0]['body']);
        self::assertStringContainsString('"pocet":10', $kit->requests[0]['body']);
    }

    public function testSearchWhenUnavailableIsEmptyWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        self::assertSame([], new AresCompanyRegistry($kit->client, $kit->cache)->searchByName('CZ', 'Example', 10));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testSearchWithBlankQueryDoesNotCallTheRegister(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-search.json'));

        self::assertSame([], new AresCompanyRegistry($kit->client, $kit->cache)->searchByName('CZ', '   ', 10));
        self::assertSame([], $kit->requests);
    }

    public function testOutgoingCallsAreRateLimited(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-not-found.json', 404), limit: 2);
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $ares->searchByName('CZ', $name, 5);
        }

        self::assertCount(2, $kit->requests);
        self::assertSame(2, $kit->logger->warnings());
    }
}
