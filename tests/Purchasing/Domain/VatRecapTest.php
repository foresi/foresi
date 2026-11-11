<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecap;
use App\Purchasing\Domain\VatRecapLine;
use App\Purchasing\Domain\VatRecapRow;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatRecapTest extends TestCase
{
    public function testACzkDocumentKeepsItsVat(): void
    {
        $recap = VatRecap::of([
            $this->line('21', 100_000, 21_000, Currency::CZK),
            $this->line('12', 50_000, 6_000, Currency::CZK),
        ], Currency::CZK, ExchangeRate::one(), PurchaseTaxTreatment::Domestic);

        self::assertSame(150_000, $recap->base()->minor());
        self::assertSame(27_000, $recap->vat()->minor());
        self::assertSame(150_000, $recap->baseCzk()?->minor());
        self::assertSame(27_000, $recap->vatCzk()?->minor());
    }

    public function testTheVatInCzkTheDocumentStatesIsTaken(): void
    {
        $line = new VatRecapLine(VatRate::of('21'), Money::ofMinor(10_000, Currency::EUR), Money::ofMinor(2_100, Currency::EUR), Money::ofMinor(51_234, Currency::CZK));
        $recap = VatRecap::of([$line], Currency::EUR, ExchangeRate::of('24.335'), PurchaseTaxTreatment::Domestic);

        self::assertSame(243_350, $recap->baseCzk()?->minor());
        self::assertSame(51_234, $recap->vatCzk()?->minor());
    }

    public function testWithoutAStatedAmountTheVatIsConvertedAtTheRate(): void
    {
        $recap = VatRecap::of([$this->line('21', 10_000, 2_100, Currency::EUR)], Currency::EUR, ExchangeRate::of('24.335'), PurchaseTaxTreatment::Domestic);

        // 21.00 EUR at 24.335 is 511.035 CZK, half up to 511.04.
        self::assertSame(51_104, $recap->vatCzk()?->minor());
    }

    public function testSelfAssessedVatIsTheBaseInCzkTimesTheRate(): void
    {
        $recap = VatRecap::of([$this->line('21', 2_000, 0, Currency::USD)], Currency::USD, ExchangeRate::of('23.456'), PurchaseTaxTreatment::NonEuService);

        // 20.00 USD is 469.12 CZK; 21 % of it is 98.5152, half up to 98.52.
        self::assertSame(46_912, $recap->baseCzk()?->minor());
        self::assertSame(9_852, $recap->vatCzk()?->minor());
        self::assertSame(0, $recap->vat()->minor());
    }

    public function testDomesticReverseChargeInCzkComputesTheTax(): void
    {
        $recap = VatRecap::of([$this->line('21', 100_000, 0, Currency::CZK)], Currency::CZK, ExchangeRate::one(), PurchaseTaxTreatment::DomesticReverseCharge);

        self::assertSame(21_000, $recap->vatCzk()?->minor());
    }

    public function testWithoutARateNothingIsInCzkButAStatedVat(): void
    {
        $stated = new VatRecapLine(VatRate::of('21'), Money::ofMinor(10_000, Currency::EUR), Money::ofMinor(2_100, Currency::EUR), Money::ofMinor(51_234, Currency::CZK));
        $recap = VatRecap::of([$stated], Currency::EUR, null, PurchaseTaxTreatment::Domestic);

        self::assertNull($recap->baseCzk());
        self::assertSame(51_234, $recap->vatCzk()?->minor());
        self::assertNull(VatRecap::of([$this->line('21', 10_000, 2_100, Currency::EUR)], Currency::EUR, null, PurchaseTaxTreatment::Domestic)->vatCzk());
    }

    public function testANonVatDocumentHasNoVatInCzk(): void
    {
        $recap = VatRecap::of([$this->line('0', 10_000, 0, Currency::EUR)], Currency::EUR, ExchangeRate::of('25'), PurchaseTaxTreatment::NotVatDocument);

        self::assertSame(250_000, $recap->baseCzk()?->minor());
        self::assertSame(0, $recap->vatCzk()?->minor());
    }

    public function testANonVatDocumentHasNoVatInCzkWithoutARate(): void
    {
        $recap = VatRecap::of([$this->line('0', 10_000, 0, Currency::EUR)], Currency::EUR, null, PurchaseTaxTreatment::NotVatDocument);

        self::assertNull($recap->baseCzk());
        self::assertSame(0, $recap->vatCzk()?->minor());
    }

    public function testAsManyRowsAsTheLimitAreTaken(): void
    {
        $lines = array_map(fn (int $rate): VatRecapLine => $this->line((string) $rate, 100, 0, Currency::CZK), range(1, VatRecap::MAX_ROWS));

        $recap = VatRecap::of($lines, Currency::CZK, ExchangeRate::one(), PurchaseTaxTreatment::Domestic);

        self::assertCount(VatRecap::MAX_ROWS, $recap->rows());
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], array_map(static fn (VatRecapRow $row): string => $row->rate->percent(), $recap->rows()));
    }

    public function testItRoundTripsThroughItsStoredForm(): void
    {
        $recap = VatRecap::of([$this->line('21', 2_000, 0, Currency::USD)], Currency::USD, ExchangeRate::of('23.456'), PurchaseTaxTreatment::EuService);

        self::assertEquals($recap, VatRecap::fromArray($recap->toArray(), Currency::USD));
    }

    /**
     * @param list<VatRecapLine> $lines
     */
    #[DataProvider('unfit')]
    public function testRefuses(array $lines, Currency $currency, PurchaseTaxTreatment $treatment, string $reason): void
    {
        try {
            VatRecap::of($lines, $currency, ExchangeRate::of('25'), $treatment);
            self::fail('The recap was taken.');
        } catch (ReceivedDocumentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    /**
     * @return iterable<string, array{list<VatRecapLine>, Currency, PurchaseTaxTreatment, string}>
     */
    public static function unfit(): iterable
    {
        $line = static fn (string $rate, int $base, int $vat, Currency $currency, ?Money $stated = null): VatRecapLine => new VatRecapLine(VatRate::of($rate), Money::ofMinor($base, $currency), Money::ofMinor($vat, $currency), $stated);

        yield 'a rate twice' => [[$line('21', 100, 21, Currency::CZK), $line('21', 200, 42, Currency::CZK)], Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_DUPLICATE_RATE];
        yield 'another currency' => [[$line('21', 100, 21, Currency::EUR)], Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_CURRENCY];
        yield 'VAT on no VAT document' => [[$line('21', 100, 21, Currency::CZK)], Currency::CZK, PurchaseTaxTreatment::NotVatDocument, ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT];
        yield 'a stated amount on a CZK document' => [[$line('21', 100, 21, Currency::CZK, Money::ofMinor(21, Currency::CZK))], Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::STATED_VAT_CZK];
        yield 'a stated amount on self-assessed VAT' => [[$line('21', 100, 0, Currency::EUR, Money::ofMinor(500, Currency::CZK))], Currency::EUR, PurchaseTaxTreatment::EuService, ReceivedDocumentRefused::STATED_VAT_CZK];
        yield 'a stated amount not in CZK' => [[$line('21', 100, 21, Currency::EUR, Money::ofMinor(500, Currency::USD))], Currency::EUR, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_CURRENCY];
        yield 'a base in another currency' => [[new VatRecapLine(VatRate::of('21'), Money::ofMinor(100, Currency::EUR), Money::ofMinor(21, Currency::CZK))], Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_CURRENCY];
        yield 'a VAT in another currency' => [[new VatRecapLine(VatRate::of('21'), Money::ofMinor(100, Currency::CZK), Money::ofMinor(21, Currency::EUR))], Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_CURRENCY];
        yield 'a rate on no VAT document' => [[$line('21', 100, 0, Currency::CZK)], Currency::CZK, PurchaseTaxTreatment::NotVatDocument, ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT];
        yield 'VAT at no rate on no VAT document' => [[$line('0', 100, 21, Currency::CZK)], Currency::CZK, PurchaseTaxTreatment::NotVatDocument, ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT];
        yield 'too many rows' => [array_map(static fn (int $rate): VatRecapLine => $line((string) $rate, 100, 0, Currency::CZK), range(1, VatRecap::MAX_ROWS + 1)), Currency::CZK, PurchaseTaxTreatment::Domestic, ReceivedDocumentRefused::VAT_RECAP_TOO_LONG];
    }

    #[DataProvider('rates')]
    public function testReadsARate(string $given, string $percent, string $factor): void
    {
        $rate = VatRate::of($given);

        self::assertSame($percent, $rate->percent());
        self::assertSame($factor, $rate->factor());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rates(): iterable
    {
        yield 'base' => ['21', '21', '0.2100'];
        yield 'reduced' => ['12.00', '12', '0.1200'];
        yield 'a fraction' => ['10.5', '10.5', '0.1050'];
        yield 'zero' => ['0', '0', '0.0000'];
        yield 'a hundred' => ['100', '100', '1.0000'];
        yield 'leading zeros' => ['05', '5', '0.0500'];
        yield 'spaces around' => [' 21 ', '21', '0.2100'];
    }

    #[DataProvider('badRates')]
    public function testRefusesARate(string $given): void
    {
        $this->expectException(ReceivedDocumentRefused::class);
        VatRate::of($given);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRates(): iterable
    {
        yield 'above a hundred' => ['100.5'];
        yield 'a hundred and one' => ['101'];
        yield 'a hundred and a bit' => ['100.01'];
        yield 'negative' => ['-21'];
        yield 'three decimals' => ['21.005'];
        yield 'text' => ['twenty'];
        yield 'empty' => [''];
    }

    #[DataProvider('exchangeRates')]
    public function testReadsAnExchangeRate(string $given, string $stored): void
    {
        self::assertSame($stored, ExchangeRate::of($given)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exchangeRates(): iterable
    {
        yield 'as published' => ['24.335', '24.335'];
        yield 'trailing zeros' => ['24.3350', '24.335'];
        yield 'one' => ['1.000', '1'];
        yield 'below one' => ['0.0425', '0.0425'];
        yield 'spaces around' => [' 24.335 ', '24.335'];
        yield 'leading zeros' => ['007.50', '7.5'];
        yield 'a whole number' => ['025', '25'];
    }

    public function testACzkAmountIsNotConverted(): void
    {
        self::assertSame(12_345, ExchangeRate::of('25')->toCzk(Money::ofMinor(12_345, Currency::CZK))->minor());
        self::assertSame(Currency::CZK, ExchangeRate::of('25')->toCzk(Money::ofMinor(100, Currency::EUR))->currency());
        self::assertSame(2_500, ExchangeRate::of('25')->toCzk(Money::ofMinor(100, Currency::EUR))->minor());
    }

    #[DataProvider('badExchangeRates')]
    public function testRefusesAnExchangeRate(string $given): void
    {
        $this->expectException(ReceivedDocumentRefused::class);
        ExchangeRate::of($given);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badExchangeRates(): iterable
    {
        yield 'zero' => ['0.000'];
        yield 'negative' => ['-24'];
        yield 'a comma' => ['24,335'];
        yield 'empty' => [''];
    }

    private function line(string $rate, int $base, int $vat, Currency $currency): VatRecapLine
    {
        return new VatRecapLine(VatRate::of($rate), Money::ofMinor($base, $currency), Money::ofMinor($vat, $currency));
    }
}
