<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use PHPUnit\Framework\TestCase;

/**
 * The CZK arithmetic in haléře: decimals read exactly, and amounts written
 * as decimal strings and as English words.
 */
final class CzkTest extends TestCase
{
    public function testADecimalIsReadToTheHaler(): void
    {
        self::assertSame(1_200, Czk::minorOf('12'));
        self::assertSame(1_250, Czk::minorOf('12.5'));
        self::assertSame(-150, Czk::minorOf('-1.5'));
        self::assertSame(-1_200, Czk::minorOf('-12'));
    }

    public function testAnythingButAPlainDecimalIsRefused(): void
    {
        foreach (['x5', '1,5', '', '1.', '--1'] as $decimal) {
            try {
                Czk::minorOf($decimal);
                self::fail(\sprintf('"%s" is refused.', $decimal));
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString('is not a decimal', $refused->getMessage());
            }
        }
    }

    public function testAnAmountIsWrittenWithItsSignAndTwoDecimals(): void
    {
        self::assertSame('0.00', Czk::text(0));
        self::assertSame('0.05', Czk::text(5));
        self::assertSame('12.34', Czk::text(1_234));
        self::assertSame('-1.50', Czk::text(-150));
        self::assertSame('17139.00', Czk::text(1_713_900));
    }

    public function testAnAmountInWordsIsWholeCzkWithThousandsApart(): void
    {
        self::assertSame('1,234,567 CZK', Czk::words(123_456_789));
        self::assertSame('0 CZK', Czk::words(99));
    }

    public function testTheRoundingsOfTheStatutes(): void
    {
        self::assertSame(1_713_900, Czk::percentUp(4_896_700, '35'));
        self::assertSame(1_713_800, Czk::percentDown(4_896_700, '35'));
        self::assertSame(2_448_350, Czk::percent(4_896_700, '50'));
        self::assertSame(10_772_800, Czk::fractionUp(11_752_100, 11, 12));
        self::assertSame(200, Czk::upToCzk(101));
        self::assertSame(-100, Czk::upToCzk(-101));
        self::assertSame(10_000, Czk::downToHundreds(19_999));
        self::assertSame(-10_000, Czk::downToHundreds(-1));
    }
}
