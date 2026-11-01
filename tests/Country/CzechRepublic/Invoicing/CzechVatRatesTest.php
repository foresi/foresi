<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\CzechSupplyCodes;
use App\Country\CzechRepublic\Invoicing\CzechVatRates;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Country\CzechRepublic\Invoicing\QrPlatba;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\SupplyCode;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CzechVatRatesTest extends TestCase
{
    public function testTheBaseRateIs21(): void
    {
        self::assertSame('21.00', new CzechVatRates()->rate(VatRateKind::Base, new \DateTimeImmutable('2026-10-31'))->decimal());
    }

    public function testTheReducedRateIs12(): void
    {
        self::assertSame('12.00', new CzechVatRates()->rate(VatRateKind::Reduced, new \DateTimeImmutable('2026-10-31'))->decimal());
    }

    public function testTheRatesApplyFromTheFirstDay(): void
    {
        $rates = new CzechVatRates();

        self::assertSame('21.00', $rates->rate(VatRateKind::Base, new \DateTimeImmutable('2024-01-01'))->decimal());
        self::assertSame('12.00', $rates->rate(VatRateKind::Reduced, new \DateTimeImmutable('2024-01-01 00:00:00'))->decimal());
    }

    public function testADateBeforeTheTableIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        new CzechVatRates()->rate(VatRateKind::Base, new \DateTimeImmutable('2023-12-31'));
    }

    public function testTheCountryAnswersForCzechia(): void
    {
        $country = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), new IsdocWriter(), new QrPlatba(new CzechVatRates()));

        self::assertTrue($country->supports('CZ'));
        self::assertFalse($country->supports('SK'));
        self::assertSame(Currency::CZK, $country->homeCurrency());
        self::assertSame('Europe/Prague', $country->timezone()->getName());
        self::assertSame('21.00', $country->rate(VatRateKind::Base, new \DateTimeImmutable('2026-10-31'))->decimal());
        self::assertSame('invoice.pdf.note.reverse_charge', $country->noteKey(TaxTreatment::ReverseCharge));
        self::assertSame('invoice.pdf.note.exempt', $country->noteKey(TaxTreatment::Exempt));
        self::assertNull($country->noteKey(TaxTreatment::Standard));
        self::assertNotSame([], $country->supplyCodes(new \DateTimeImmutable('2026-10-31')));
    }

    public function testTheRegistryFindsTheCountryAndRefusesAnother(): void
    {
        $czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), new IsdocWriter(), new QrPlatba(new CzechVatRates()));
        $countries = new InvoicingCountries([$czech]);

        self::assertSame($czech, $countries->for('CZ'));
        $this->expectException(UnsupportedCountry::class);
        $countries->for('SK');
    }

    public function testSupplyCodesHoldTheKnownRegimes(): void
    {
        $codes = array_map(
            static fn (SupplyCode $code): string => $code->code,
            new CzechSupplyCodes()->on(new \DateTimeImmutable('2026-10-31')),
        );

        foreach (['1', '1a', '3', '3a', '4', '4a', '5', '6', '7', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21'] as $expected) {
            self::assertContains($expected, $codes);
        }
        self::assertSame(array_unique($codes), $codes);
    }

    public function testSupplyCodesAreDated(): void
    {
        $codes = new CzechSupplyCodes();
        $on = static fn (string $date): array => array_map(static fn (SupplyCode $c): string => $c->code, $codes->on(new \DateTimeImmutable($date)));

        self::assertNotContains('4a', $on('2017-06-30'));
        self::assertContains('4a', $on('2017-07-01'));
        self::assertNotContains('21', $on('2016-09-30'));
        self::assertContains('21', $on('2016-10-01'));
        self::assertNotContains('19', $on('2016-01-31'));
        self::assertContains('19', $on('2016-02-01'));
        self::assertSame([], $on('2015-12-31'));
    }

    public function testEachCodeCarriesALabelKey(): void
    {
        foreach (new CzechSupplyCodes()->on(new \DateTimeImmutable('2026-10-31')) as $code) {
            self::assertSame('invoice.supply_code.'.$code->code, $code->labelKey);
        }
    }
}
