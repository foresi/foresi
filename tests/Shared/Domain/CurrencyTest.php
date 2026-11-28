<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Domain;

use Foresi\Shared\Domain\Currencies;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\UnknownCurrency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    /**
     * The minor units of every code, pinned (ADR 0049). Money is stored as
     * integer minor units, so a brick/money update that changed the exponent
     * of a code would silently change the value of every stored amount in it.
     * This test fails first. A new code is added here; a changed one needs a
     * data migration and a new ADR, never an edit of this list.
     */
    private const array MINOR_UNITS = [
        'ADP' => 0, 'AED' => 2, 'AFA' => 2, 'AFN' => 2, 'ALL' => 2, 'AMD' => 2, 'ANG' => 2, 'AOA' => 2,
        'ARS' => 2, 'ATS' => 2, 'AUD' => 2, 'AWG' => 2, 'AZM' => 2, 'AZN' => 2, 'BAM' => 2, 'BBD' => 2,
        'BDT' => 2, 'BEF' => 0, 'BGL' => 2, 'BGN' => 2, 'BHD' => 3, 'BIF' => 0, 'BMD' => 2, 'BND' => 2,
        'BOB' => 2, 'BOV' => 2, 'BRL' => 2, 'BSD' => 2, 'BTN' => 2, 'BWP' => 2, 'BYN' => 2, 'BYR' => 0,
        'BZD' => 2, 'CAD' => 2, 'CDF' => 2, 'CHE' => 2, 'CHF' => 2, 'CHW' => 2, 'CLF' => 4, 'CLP' => 0,
        'CNY' => 2, 'COP' => 2, 'COU' => 2, 'CRC' => 2, 'CUC' => 2, 'CUP' => 2, 'CVE' => 2, 'CYP' => 2,
        'CZK' => 2, 'DEM' => 2, 'DJF' => 0, 'DKK' => 2, 'DOP' => 2, 'DZD' => 2, 'EEK' => 2, 'EGP' => 2,
        'ERN' => 2, 'ESP' => 0, 'ETB' => 2, 'EUR' => 2, 'FIM' => 2, 'FJD' => 2, 'FKP' => 2, 'FRF' => 2,
        'GBP' => 2, 'GEL' => 2, 'GHC' => 2, 'GHS' => 2, 'GIP' => 2, 'GMD' => 2, 'GNF' => 0, 'GRD' => 0,
        'GTQ' => 2, 'GWP' => 2, 'GYD' => 2, 'HKD' => 2, 'HNL' => 2, 'HRK' => 2, 'HTG' => 2, 'HUF' => 2,
        'IDR' => 2, 'IEP' => 2, 'ILS' => 2, 'INR' => 2, 'IQD' => 3, 'IRR' => 2, 'ISK' => 0, 'ITL' => 0,
        'JMD' => 2, 'JOD' => 3, 'JPY' => 0, 'KES' => 2, 'KGS' => 2, 'KHR' => 2, 'KMF' => 0, 'KPW' => 2,
        'KRW' => 0, 'KWD' => 3, 'KYD' => 2, 'KZT' => 2, 'LAK' => 2, 'LBP' => 2, 'LKR' => 2, 'LRD' => 2,
        'LSL' => 2, 'LTL' => 2, 'LUF' => 0, 'LVL' => 2, 'LYD' => 3, 'MAD' => 2, 'MDL' => 2, 'MGA' => 2,
        'MGF' => 0, 'MKD' => 2, 'MMK' => 2, 'MNT' => 2, 'MOP' => 2, 'MRO' => 2, 'MRU' => 2, 'MTL' => 2,
        'MUR' => 2, 'MVR' => 2, 'MWK' => 2, 'MXN' => 2, 'MXV' => 2, 'MYR' => 2, 'MZM' => 2, 'MZN' => 2,
        'NAD' => 2, 'NGN' => 2, 'NIO' => 2, 'NLG' => 2, 'NOK' => 2, 'NPR' => 2, 'NZD' => 2, 'OMR' => 3,
        'PAB' => 2, 'PEN' => 2, 'PGK' => 2, 'PHP' => 2, 'PKR' => 2, 'PLN' => 2, 'PTE' => 0, 'PYG' => 0,
        'QAR' => 2, 'ROL' => 2, 'RON' => 2, 'RSD' => 2, 'RUB' => 2, 'RUR' => 2, 'RWF' => 0, 'SAR' => 2,
        'SBD' => 2, 'SCR' => 2, 'SDD' => 2, 'SDG' => 2, 'SEK' => 2, 'SGD' => 2, 'SHP' => 2, 'SIT' => 2,
        'SKK' => 2, 'SLE' => 2, 'SLL' => 2, 'SOS' => 2, 'SRD' => 2, 'SRG' => 2, 'SSP' => 2, 'STD' => 2,
        'STN' => 2, 'SVC' => 2, 'SYP' => 2, 'SZL' => 2, 'THB' => 2, 'TJS' => 2, 'TMM' => 2, 'TMT' => 2,
        'TND' => 3, 'TOP' => 2, 'TPE' => 0, 'TRL' => 0, 'TRY' => 2, 'TTD' => 2, 'TWD' => 2, 'TZS' => 2,
        'UAH' => 2, 'UGX' => 0, 'USD' => 2, 'USN' => 2, 'USS' => 2, 'UYI' => 0, 'UYU' => 2, 'UYW' => 4,
        'UZS' => 2, 'VEB' => 2, 'VED' => 2, 'VEF' => 2, 'VES' => 2, 'VND' => 0, 'VUV' => 0, 'WST' => 2,
        'XAD' => 2, 'XAF' => 0, 'XCD' => 2, 'XCG' => 2, 'XOF' => 0, 'XPF' => 0, 'YER' => 2, 'YUM' => 2,
        'ZAR' => 2, 'ZMK' => 2, 'ZMW' => 2, 'ZWD' => 2, 'ZWG' => 2, 'ZWL' => 2,
    ];

    public function testMinorUnitsOfEveryCodeArePinned(): void
    {
        $actual = [];
        foreach (Currencies::all() as $currency) {
            $actual[$currency->code()] = $currency->minorUnits();
        }

        foreach (self::MINOR_UNITS as $code => $minorUnits) {
            self::assertArrayHasKey($code, $actual, \sprintf('%s is no longer known: a stored amount in it would not load.', $code));
            self::assertSame($minorUnits, $actual[$code], \sprintf('The minor units of %s changed.', $code));
        }
        self::assertSame([], array_keys(array_diff_key($actual, self::MINOR_UNITS)), 'A new code: pin its minor units here.');
    }

    public function testOneInstancePerCode(): void
    {
        self::assertSame(Currency::of('EUR'), Currency::of('EUR'));
        self::assertSame(Currency::of('EUR'), Currency::from('EUR'));
        self::assertSame(Currency::of('EUR'), Currency::tryFrom('EUR'));
        self::assertNotSame(Currency::of('EUR'), Currency::of('USD'));
        self::assertTrue(Currency::of('EUR')->equals(Currency::of('EUR')));
        self::assertFalse(Currency::of('EUR')->equals(Currency::of('CZK')));
    }

    public function testMinorUnitsFollowIso(): void
    {
        self::assertSame(0, Currency::of('JPY')->minorUnits());
        self::assertSame(2, Currency::of('CZK')->minorUnits());
        self::assertSame(3, Currency::of('BHD')->minorUnits());
        self::assertSame(4, Currency::of('CLF')->minorUnits());
    }

    public function testDescribesTheCurrency(): void
    {
        $euro = Currency::of('EUR');

        self::assertSame('EUR', $euro->code());
        self::assertSame('Euro', $euro->name());
        self::assertSame(978, $euro->numericCode());
        self::assertFalse($euro->isHistoric());
        self::assertSame('EUR', (string) $euro);
        self::assertSame('"EUR"', json_encode($euro, \JSON_THROW_ON_ERROR));
    }

    public function testHistoricCodeStillLoads(): void
    {
        $kuna = Currency::of('HRK');

        self::assertTrue($kuna->isHistoric());
        self::assertSame(2, $kuna->minorUnits());
        self::assertSame(12345, Money::ofMinor(12345, $kuna)->minor());
        self::assertNotContains($kuna, Currencies::current());
        self::assertContains($kuna, Currencies::all());
    }

    public function testUnknownCode(): void
    {
        self::assertNull(Currency::tryFrom('XYZ'));
        self::assertNull(Currency::tryFrom('eur'));
        self::assertNull(Currency::tryFrom(''));
        self::assertNull(Currency::tryFrom(null));
        self::assertFalse(Currencies::isKnown('XYZ'));
        self::assertTrue(Currencies::isKnown('BGN'));

        $this->expectException(UnknownCurrency::class);
        Currency::of('XYZ');
    }

    public function testFromRefusesAnUnknownCodeLikeAnEnum(): void
    {
        $this->expectException(\ValueError::class);
        Currency::from('XYZ');
    }

    public function testCurrentListsCodesInUseByCode(): void
    {
        $codes = Currencies::currentCodes();

        self::assertContains('CZK', $codes);
        self::assertContains('JPY', $codes);
        self::assertNotContains('HRK', $codes);
        $sorted = $codes;
        sort($sorted);
        self::assertSame($sorted, $codes);
    }

    public function testPickerPutsHomeThenUsedThenTheRest(): void
    {
        $picker = Currencies::forPicker(Currency::of('CZK'), [Currency::of('USD'), Currency::of('EUR'), Currency::of('CZK')]);
        $codes = array_map(static fn (Currency $currency): string => $currency->code(), $picker);

        self::assertSame(['CZK', 'EUR', 'USD'], \array_slice($codes, 0, 3));
        self::assertCount(\count(Currencies::current()), $codes);
        self::assertCount(\count($codes), array_unique($codes));
    }

    public function testDeprecatedValueIsTheCode(): void
    {
        self::assertSame('EUR', Currency::of('EUR')->value);
    }
}
