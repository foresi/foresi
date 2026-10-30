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

        $record = new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687'));

        self::assertNotNull($record);
        self::assertSame('Example Company s.r.o.', $record->name);
        self::assertSame('12345687', $record->registrationNumber->value);
        self::assertSame('CZ12345687', $record->vatId?->toString());
        self::assertSame('Příkladná 1444/2a', $record->seat?->street);
        self::assertSame('Ostrava', $record->seat->city);
        self::assertSame('702 00', $record->seat->postalCode);
        self::assertSame('CZ', $record->seat->countryCode);
        self::assertSame('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345687', $kit->requests[0]['url']);
    }

    public function testUnknownNumberIsNullWithoutWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-not-found.json', 404));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertSame(0, $kit->logger->warnings());
    }

    public function testUnknownNumberIsCachedSoTheRegisterIsNotAskedAgain(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-not-found.json', 404));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        $ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '1234 5687'));
        $ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687'));

        self::assertCount(1, $kit->requests);
    }

    public function testServerErrorIsNullWithLoggedWarningAndNotCached(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 503]));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));

        self::assertSame(2, $kit->logger->warnings());
        self::assertCount(2, $kit->requests);
    }

    public function testTransportFailureIsNullWithLoggedWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection timed out']));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testNonJsonAnswerIsNullWithLoggedWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('<html>maintenance</html>'));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testOtherCountriesAreNotAskedAbout(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-subject.json'));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('SK', '12345687')));
        self::assertSame([], $ares->searchByName('SK', 'Example', 10));
        self::assertSame([], $kit->requests);
    }

    public function testRequestsUseTimeoutAndIdentifyingUserAgentWithoutPersonalData(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-subject.json'));

        new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687'));

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
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-search.json'), limit: 2);
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $ares->searchByName('CZ', $name, 5);
        }

        self::assertCount(2, $kit->requests);
        self::assertSame(2, $kit->logger->warnings());
    }

    public function testClientErrorsAreNullWithWarningAndNotCached(): void
    {
        foreach ([400, 403, 429] as $status) {
            $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('{}', ['http_code' => $status]));
            $ares = new AresCompanyRegistry($kit->client, $kit->cache);

            self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
            self::assertNull($ares->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
            self::assertSame(2, $kit->logger->warnings());
            self::assertCount(2, $kit->requests);
        }
    }

    public function testSearchWithTooManyMatchesIsEmptyWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('{"kod":"CHYBA_VSTUPU"}', ['http_code' => 400]));

        self::assertSame([], new AresCompanyRegistry($kit->client, $kit->cache)->searchByName('CZ', 'a', 10));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testIncompleteSubjectIsNullWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('{"ico":"12345687"}', ['http_code' => 200]));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testSearchCacheKeyIgnoresInnerWhitespaceCaseAndUnicodeForm(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => RegistryTestKit::json('ares-search.json'));
        $ares = new AresCompanyRegistry($kit->client, $kit->cache);

        $ares->searchByName('CZ', "Př\u{00ED}klad  s.r.o.", 10);
        $ares->searchByName('CZ', " pr\u{0131}klad", 10);
        $ares->searchByName('CZ', "P\u{0159}i\u{0301}klad \t s.r.o.", 10);
        $ares->searchByName('CZ', "P\u{0159}\u{00ED}klad s.r.o.", 10);

        self::assertCount(2, $kit->requests);
    }

    public function testOversizedAnswerIsNullWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse(str_repeat('x', 2097153)));

        self::assertNull(new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testLogsHoldTheRegistryAndExceptionClassButNeverTheNumberOrUrl(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['error' => 'timeout for https://ares.gov.cz/x/12345687']));

        new AresCompanyRegistry($kit->client, $kit->cache)->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687'));

        $logged = (string) json_encode($kit->logger->records);
        self::assertStringNotContainsString('12345687', $logged);
        self::assertStringNotContainsString('ares.gov.cz', $logged);
        self::assertSame(1, $kit->logger->warnings());
    }
}
