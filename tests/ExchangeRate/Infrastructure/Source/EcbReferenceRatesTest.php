<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Infrastructure\Source;

use Foresi\ExchangeRate\Infrastructure\Source\EcbReferenceRates;
use Foresi\Tests\Support\Registry\RegistryTestKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The eurofxref files of the ECB, as the bank wrote them on 25 November 2026:
 * the daily file and the first days of the 90-day history.
 */
final class EcbReferenceRatesTest extends TestCase
{
    public function testTheDailyFileHasOneDayPricedInEuros(): void
    {
        $days = EcbReferenceRates::parse(RegistryTestKit::fixtureIn('ExchangeRate', 'ecb-daily-2026-11-25.xml'));

        self::assertSame(['2026-11-25'], array_map(strval(...), array_keys($days)));
        $published = $days['2026-11-25'];
        self::assertSame('EUR', $published->base);
        self::assertSame('2026-11-25 00:00:00 Europe/Berlin', $published->publishedOn->format('Y-m-d H:i:s e'));
        self::assertCount(29, $published->quotes);
        self::assertArrayNotHasKey('EUR', $published->quotes);
    }

    public function testEachRateIsKeptWithoutRoundingAsWholeUnits(): void
    {
        $quotes = EcbReferenceRates::parse(RegistryTestKit::fixtureIn('ExchangeRate', 'ecb-daily-2026-11-25.xml'))['2026-11-25']->quotes;

        // 1 EUR is 1.0935 USD: 10935 USD cost 10000 EUR.
        self::assertSame(['USD', 10935, '10000'], [$quotes['USD']->currency, $quotes['USD']->amount, $quotes['USD']->rate]);
        self::assertSame([24435, '1000'], [$quotes['CZK']->amount, $quotes['CZK']->rate]);
        self::assertSame([2005572, '100'], [$quotes['IDR']->amount, $quotes['IDR']->rate]);
        // "0.83730" and "137.20" lose their trailing zeros first.
        self::assertSame([8373, '10000'], [$quotes['GBP']->amount, $quotes['GBP']->rate]);
        self::assertSame([1372, '10'], [$quotes['ISK']->amount, $quotes['ISK']->rate]);
    }

    public function testTheHistoryHasItsDaysNewestFirst(): void
    {
        $days = EcbReferenceRates::parse(RegistryTestKit::fixtureIn('ExchangeRate', 'ecb-hist-90d-2026-11-25.xml'));

        self::assertSame(['2026-11-25', '2026-11-24', '2026-11-23', '2026-11-20', '2026-11-19', '2026-11-18', '2026-11-17'], array_map(strval(...), array_keys($days)));
        self::assertSame([24469, '1000'], [$days['2026-11-20']->quotes['CZK']->amount, $days['2026-11-20']->quotes['CZK']->rate]);
        self::assertSame([1099, '1000'], [$days['2026-11-20']->quotes['USD']->amount, $days['2026-11-20']->quotes['USD']->rate]);
    }

    #[DataProvider('unreadable')]
    public function testAnythingElseIsRefused(string $xml): void
    {
        $this->expectException(\UnexpectedValueException::class);

        EcbReferenceRates::parse($xml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        $envelope = static fn (string $cubes): string => '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="'.EcbReferenceRates::NAMESPACE.'"><Cube>'.$cubes.'</Cube></gesmes:Envelope>';

        yield 'empty' => [''];
        yield 'html' => ['<html><body>Maintenance</body></html>'];
        yield 'not xml' => ['USD 1.0935'];
        yield 'no day' => [$envelope('')];
        yield 'another namespace' => [str_replace(EcbReferenceRates::NAMESPACE, 'http://example.com/rates', $envelope('<Cube time="2026-11-25"><Cube currency="USD" rate="1.0935"/></Cube>'))];
        yield 'not a day' => [$envelope('<Cube time="2026-02-31"><Cube currency="USD" rate="1.0935"/></Cube>')];
        yield 'a day without rates' => [$envelope('<Cube time="2026-11-25"></Cube>')];
        yield 'a lower case code' => [$envelope('<Cube time="2026-11-25"><Cube currency="usd" rate="1.0935"/></Cube>')];
        yield 'the euro itself' => [$envelope('<Cube time="2026-11-25"><Cube currency="EUR" rate="1"/></Cube>')];
        yield 'a rate with text' => [$envelope('<Cube time="2026-11-25"><Cube currency="USD" rate="n/a"/></Cube>')];
        yield 'a negative rate' => [$envelope('<Cube time="2026-11-25"><Cube currency="USD" rate="-1.0935"/></Cube>')];
        yield 'a zero rate' => [$envelope('<Cube time="2026-11-25"><Cube currency="USD" rate="0.000"/></Cube>')];
        yield 'too many digits' => [$envelope('<Cube time="2026-11-25"><Cube currency="IDR" rate="1234567.891"/></Cube>')];
        yield 'too many decimals' => [$envelope('<Cube time="2026-11-25"><Cube currency="USD" rate="1.0935001"/></Cube>')];
    }
}
