<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\ExchangeRate;

use App\Country\CzechRepublic\ExchangeRate\CnbDailyFix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CnbDailyFixTest extends TestCase
{
    public function testTheDateAndEveryCurrencyAreRead(): void
    {
        $published = CnbDailyFix::parse($this->fixture('cnb-2026-11-10.txt'));

        self::assertSame('2026-11-10', $published->publishedOn->format('Y-m-d'));
        self::assertCount(30, $published->quotes);
        self::assertSame('24.335', $published->quotes['EUR']->rate);
        self::assertSame(1, $published->quotes['EUR']->amount);
        self::assertSame('21.702', $published->quotes['USD']->rate);
    }

    public function testAmountsOfOneHundredAndOneThousandAreKept(): void
    {
        $quotes = CnbDailyFix::parse($this->fixture('cnb-2026-11-10.txt'))->quotes;

        self::assertSame(100, $quotes['JPY']->amount);
        self::assertSame('13.717', $quotes['JPY']->rate);
        self::assertSame(1000, $quotes['IDR']->amount);
        self::assertSame('1.202', $quotes['IDR']->rate);
    }

    public function testEveryQuoteKnowsItsCurrency(): void
    {
        foreach (CnbDailyFix::parse($this->fixture('cnb-2026-11-10.txt'))->quotes as $code => $quote) {
            self::assertSame($code, $quote->currency);
        }
    }

    public function testAFileWithOneRateIsRead(): void
    {
        $published = CnbDailyFix::parse("10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|24,335");

        self::assertSame(['EUR'], array_keys($published->quotes));
        self::assertSame('EUR', $published->quotes['EUR']->currency);
        self::assertSame(1, $published->quotes['EUR']->amount);
    }

    public function testAFileOfAPreviousDayKeepsItsOwnDate(): void
    {
        self::assertSame('2026-11-06', CnbDailyFix::parse($this->fixture('cnb-2026-11-06.txt'))->publishedOn->format('Y-m-d'));
    }

    public function testWindowsLineEndingsAreRead(): void
    {
        $text = str_replace("\n", "\r\n", $this->fixture('cnb-2026-11-10.txt'));

        self::assertCount(30, CnbDailyFix::parse($text)->quotes);
    }

    #[DataProvider('unreadable')]
    public function testAnythingElseIsRefused(string $text): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CnbDailyFix::parse($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'html' => ['<html><body>Maintenance</body></html>'];
        yield 'no rates' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\n"];
        yield 'not a day' => ["31.02.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|24,335\n"];
        yield 'other columns' => ["10.11.2026 #216\nland|currency|amount|code|rate\nEMU|euro|1|EUR|24,335\n"];
        yield 'a short line' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR\n"];
        yield 'a zero amount' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|0|EUR|24,335\n"];
        yield 'a rate with text' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|n/a\n"];
        yield 'a date with a prefix' => ["on 10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|24,335\n"];
        yield 'an amount with a prefix' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|x1|EUR|24,335\n"];
        yield 'a code with a prefix' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|xEUR|24,335\n"];
        yield 'a rate with a prefix' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|x24,335\n"];
        yield 'a lower case code' => ["10.11.2026 #216\nzemě|měna|množství|kód|kurz\nEMU|euro|1|eur|24,335\n"];
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/../../../Fixtures/ExchangeRate/'.$name);
        \assert(\is_string($content));

        return $content;
    }
}
