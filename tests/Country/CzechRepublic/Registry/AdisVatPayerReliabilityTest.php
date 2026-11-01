<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Registry;

use App\Country\CzechRepublic\Registry\AdisVatPayerReliability;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdisVatPayerReliabilityTest extends TestCase
{
    public function testUnreliablePayerWithPublishedAccounts(): void
    {
        $kit = $this->kit('adis-unreliable.xml');

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        self::assertSame(RegistrySource::UnreliablePayer, $result->check->source);
        self::assertSame(RegistryResult::Unreliable, $result->check->result);
        self::assertEquals($kit->clock->now(), $result->check->checkedAt);
        self::assertSame(['2000000011/0800', '19-2000000007/0800', 'CZ7308000000002000000007'], $result->publishedBankAccounts);
    }

    public function testRequestIsASoapEnvelopeBuiltAsXmlWithTimeoutAndUserAgent(): void
    {
        $kit = $this->kit('adis-unreliable.xml');

        new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        $request = $kit->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP', $request['url']);
        self::assertStringContainsString('<roz:dic>12345687</roz:dic>', $request['body']);
        self::assertContains('Content-Type: text/xml; charset=utf-8', $request['headers']);
        self::assertContains('SOAPAction: "http://adis.mfcr.cz/rozhraniCRPDPH/getStatusNespolehlivyPlatceRozsireny"', $request['headers']);
        self::assertContains('User-Agent: Foresi (+https://foresi.app)', $request['headers']);
        self::assertSame(5.0, $request['timeout']);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($request['body']));
    }

    public function testReliablePayerAndNotRegisteredPayerInOneBatch(): void
    {
        $kit = $this->kit('adis-reliable.xml');

        $results = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany([VatId::fromString('CZ12345695'), VatId::fromString('CZ99999994')]);

        self::assertSame(RegistryResult::Reliable, $results['CZ12345695']->check->result);
        self::assertSame(['2000000026/0800'], $results['CZ12345695']->publishedBankAccounts);
        self::assertSame(RegistryResult::NotRegistered, $results['CZ99999994']->check->result);
        self::assertSame([], $results['CZ99999994']->publishedBankAccounts);
        self::assertCount(1, $kit->requests);
        self::assertSame(0, $kit->logger->warnings());
    }

    public function testNotRegisteredIsCachedLikeAnyOtherAnswer(): void
    {
        $kit = $this->kit('adis-reliable.xml');
        $adis = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock);
        $adis->check(VatId::fromString('CZ99999994'));

        self::assertSame(RegistryResult::NotRegistered, $adis->check(VatId::fromString('CZ99999994'))->check->result);
        self::assertCount(1, $kit->requests);
    }

    public function testIdLeftOutOfTheAnswerIsUnavailableWithWarning(): void
    {
        $kit = $this->kit('adis-reliable.xml');

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        self::assertSame(RegistryResult::Unavailable, $result->check->result);
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testClientErrorStatusIsUnavailableWithWarning(): void
    {
        foreach ([400, 403, 429] as $status) {
            $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse(RegistryTestKit::fixture('adis-unreliable.xml'), ['http_code' => $status]));

            $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

            self::assertSame(RegistryResult::Unavailable, $result->check->result);
            self::assertSame(1, $kit->logger->warnings());
        }
    }

    public function testBatchesOf100(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse(RegistryTestKit::fixture('adis-reliable.xml')));
        $ids = [];
        for ($i = 0; $i < 150; ++$i) {
            $ids[] = VatId::fromString('CZ'.(99000000 + $i));
        }

        $results = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany($ids);

        self::assertCount(2, $kit->requests);
        self::assertSame(100, substr_count($kit->requests[0]['body'], '<roz:dic>'));
        self::assertSame(50, substr_count($kit->requests[1]['body'], '<roz:dic>'));
        self::assertCount(150, $results);
    }

    public function testDuplicateIdsAreAskedOnce(): void
    {
        $kit = $this->kit('adis-unreliable.xml');

        $results = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany([VatId::fromString('CZ12345687'), VatId::fromString('cz12345687')]);

        self::assertCount(1, $results);
        self::assertSame(1, substr_count($kit->requests[0]['body'], '<roz:dic>'));
    }

    public function testAnswersAreCachedForTheNextCall(): void
    {
        $kit = $this->kit('adis-unreliable.xml');
        $adis = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock);
        $adis->check(VatId::fromString('CZ12345687'));

        $kit->clock->modify('+1 hour');
        $again = $adis->check(VatId::fromString('CZ12345687'));

        self::assertCount(1, $kit->requests);
        self::assertSame(RegistryResult::Unreliable, $again->check->result);
        self::assertCount(3, $again->publishedBankAccounts);
        self::assertSame('2026-10-30 10:00:00', $again->check->checkedAt->format('Y-m-d H:i:s'));
    }

    public function testOnlyUncachedIdsAreSent(): void
    {
        $kit = $this->kit('adis-reliable.xml');
        $adis = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock);
        $adis->check(VatId::fromString('CZ12345695'));

        $adis->checkMany([VatId::fromString('CZ12345695'), VatId::fromString('CZ12345687')]);

        self::assertSame(1, substr_count($kit->requests[1]['body'], '<roz:dic>'));
        self::assertStringContainsString('12345687', $kit->requests[1]['body']);
    }

    public function testForeignVatIdIsUnavailableWithoutACall(): void
    {
        $kit = $this->kit('adis-unreliable.xml');

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('DE123456789'));

        self::assertSame(RegistryResult::Unavailable, $result->check->result);
        self::assertSame([], $kit->requests);
    }

    public function testServerErrorIsUnavailableWithWarning(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        self::assertSame(RegistryResult::Unavailable, $result->check->result);
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testTimeoutIsUnavailableWithWarningAndNotCached(): void
    {
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $adis = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock);

        self::assertSame(RegistryResult::Unavailable, $adis->check(VatId::fromString('CZ12345687'))->check->result);
        self::assertSame(RegistryResult::Unavailable, $adis->check(VatId::fromString('CZ12345687'))->check->result);
        self::assertCount(2, $kit->requests);
        self::assertSame(2, $kit->logger->warnings());
    }

    public function testMalformedAndDoctypeAnswersAreUnavailable(): void
    {
        foreach (['<not-xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>', ''] as $body) {
            $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse($body));

            self::assertSame(RegistryResult::Unavailable, new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'))->check->result);
            self::assertSame(1, $kit->logger->warnings());
        }
    }

    public function testTheRequestBodyIsExactlyTheSoapEnvelope(): void
    {
        $kit = $this->kit('adis-reliable.xml');

        new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany([VatId::fromString('CZ12345687'), VatId::fromString('CZ12345695')]);

        self::assertSame(
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:roz="http://adis.mfcr.cz/rozhraniCRPDPH/">'
            .'<soapenv:Body><roz:StatusNespolehlivyPlatceRozsirenyRequest><roz:dic>12345687</roz:dic><roz:dic>12345695</roz:dic></roz:StatusNespolehlivyPlatceRozsirenyRequest></soapenv:Body>'
            .'</soapenv:Envelope>',
            $kit->requests[0]['body'],
        );
    }

    public function testIdsAfterADuplicateOrAForeignIdAreStillAsked(): void
    {
        $kit = $this->kit('adis-reliable.xml');

        $results = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany([
            VatId::fromString('CZ12345687'),
            VatId::fromString('CZ12345687'),
            VatId::fromString('DE123456789'),
            VatId::fromString('CZ12345695'),
        ]);

        self::assertSame(2, substr_count($kit->requests[0]['body'], '<roz:dic>'));
        self::assertStringContainsString('<roz:dic>12345695</roz:dic>', $kit->requests[0]['body']);
        self::assertSame(RegistryResult::Reliable, $results['CZ12345695']->check->result);
        self::assertSame(RegistryResult::Unavailable, $results['DE123456789']->check->result);
    }

    public function testAnAnswerWithADoctypeIsRejectedEvenWithoutAnEntity(): void
    {
        $withDoctype = str_replace('?>', "?>\n<!DOCTYPE soapenv:Envelope>", RegistryTestKit::fixture('adis-reliable.xml'));
        self::assertStringContainsString('<!DOCTYPE', $withDoctype);
        $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse($withDoctype));

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345695'));

        self::assertSame(RegistryResult::Unavailable, $result->check->result);
        self::assertSame(1, $kit->logger->warnings());
    }

    public function testTheXmlParserStateIsRestoredWhateverTheAnswer(): void
    {
        foreach ([false, true] as $before) {
            foreach (['<not-xml', RegistryTestKit::fixture('adis-reliable.xml')] as $body) {
                libxml_use_internal_errors($before);
                $kit = new RegistryTestKit(static fn (): MockResponse => new MockResponse($body));

                new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345695'));

                self::assertSame($before, libxml_use_internal_errors());
                self::assertSame([], libxml_get_errors());
            }
        }
        libxml_use_internal_errors(false);
    }

    public function testAnUnknownPayerStatusIsUnavailableWithWarningAndTheRestOfTheAnswerIsStillRead(): void
    {
        $kit = new RegistryTestKit(fn (): MockResponse => $this->payers(
            '<statusPlatceDPH dic="12345687" nespolehlivyPlatce="MAYBE"/><statusPlatceDPH dic="12345695" nespolehlivyPlatce="NE"/>',
        ));

        $results = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->checkMany([VatId::fromString('CZ12345687'), VatId::fromString('CZ12345695')]);

        self::assertSame(RegistryResult::Unavailable, $results['CZ12345687']->check->result);
        self::assertSame(RegistryResult::Reliable, $results['CZ12345695']->check->result);
        // One warning for the unknown status, one for the id the answer then leaves out.
        self::assertSame(2, $kit->logger->warnings());
    }

    public function testPublishedAccountsAreReadAsTheyAreWrittenOnTheInvoice(): void
    {
        $accounts = '<zverejneneUcty>'
            .'<ucet><standardniUcet predcisli="000019" cislo="2000000007" kodBanky="0800"/></ucet>'
            .'<ucet><standardniUcet predcisli="000000" cislo="123" kodBanky="0100"/></ucet>'
            .'<ucet><standardniUcet predcisli="" cislo="" kodBanky=""/></ucet>'
            .'<ucet><standardniUcet cislo="" kodBanky="0800"/></ucet>'
            .'<ucet><nestandardniUcet cislo=""/></ucet>'
            .'<ucet><nestandardniUcet cislo="DE89370400440532013000"/></ucet>'
            .'</zverejneneUcty>';
        $kit = new RegistryTestKit(fn (): MockResponse => $this->payers('<statusPlatceDPH dic="12345687" nespolehlivyPlatce="NE">'.$accounts.'</statusPlatceDPH>'));

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        self::assertSame(['19-2000000007/0800', '123/0100', '/0800', 'DE89370400440532013000'], $result->publishedBankAccounts);
    }

    public function testOnlyTextAccountsAreTakenFromTheCacheAsAList(): void
    {
        $kit = $this->kit('adis-reliable.xml');
        $kit->cache->put('adis', 'CZ12345687', [
            'result' => RegistryResult::Reliable->value,
            'accounts' => [5, '1/0100', ['x'], '2/0100'],
            'checkedAt' => '2026-10-30T09:00:00+00:00',
        ]);

        $result = new AdisVatPayerReliability($kit->client, $kit->cache, $kit->clock)->check(VatId::fromString('CZ12345687'));

        self::assertSame(['1/0100', '2/0100'], $result->publishedBankAccounts);
        self::assertSame([], $kit->requests);
    }

    private function payers(string $payers): MockResponse
    {
        return new MockResponse(
            '<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
            .'<StatusNespolehlivyPlatceRozsirenyResponse xmlns="http://adis.mfcr.cz/rozhraniCRPDPH/">'.$payers.'</StatusNespolehlivyPlatceRozsirenyResponse>'
            .'</soapenv:Body></soapenv:Envelope>',
            ['response_headers' => ['content-type: text/xml']],
        );
    }

    private function kit(string $fixture): RegistryTestKit
    {
        return new RegistryTestKit(static fn (): MockResponse => new MockResponse(RegistryTestKit::fixture($fixture), ['response_headers' => ['content-type: text/xml']]));
    }
}
