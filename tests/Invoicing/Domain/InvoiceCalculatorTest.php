<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\DocumentTotals;
use App\Invoicing\Domain\InvoiceCalculator;
use App\Invoicing\Domain\LineValues;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string, string, string, 7?: string}>
     */
    public static function lines(): iterable
    {
        yield 'net price' => ['3100.00', '1', 'without', '21', '3100.00', '651.00', '3751.00'];
        yield 'gross price' => ['121.00', '1', 'with', '21', '100.00', '21.00', '121.00'];
        yield 'reduced' => ['100.00', '1', 'without', '12', '100.00', '12.00', '112.00'];
        yield 'half year' => ['1000.00', '0.5', 'without', '21', '500.00', '105.00', '605.00'];
        yield 'discount 10 %' => ['200.00', '1', 'without', '21', '180.00', '37.80', '217.80', '10'];
        yield 'base only' => ['100.00', '1', 'base', '21', '100.00', '0.00', '100.00'];
        yield 'zero value' => ['0.00', '1', 'without', '21', '0.00', '0.00', '0.00'];
        yield 'gross reduced' => ['112.00', '1', 'with', '12', '100.00', '12.00', '112.00'];
        yield 'gross price rounds the net' => ['100.00', '1', 'with', '21', '82.64', '17.36', '100.00'];
        yield 'gross with quantity and discount' => ['100.00', '3', 'with', '21', '223.14', '46.86', '270.00', '10'];
        yield 'half up on the amount' => ['0.05', '1.5', 'without', '21', '0.08', '0.02', '0.10'];
        yield 'one rounding for quantity and discount' => ['10.01', '0.5', 'without', '21', '2.50', '0.53', '3.03', '50'];
        yield 'discount of 100 %' => ['500.00', '2', 'without', '21', '0.00', '0.00', '0.00', '100'];
        yield 'negative price' => ['-100.00', '1', 'without', '21', '-100.00', '-21.00', '-121.00'];
    }

    #[DataProvider('lines')]
    public function testALine(string $price, string $quantity, string $type, string $rate, string $net, string $vat, string $gross, string $discount = '0'): void
    {
        $amounts = $this->calculator()->line($this->line($price, $quantity, $type, $rate, discount: $discount));

        self::assertSame($this->minor($net), $amounts->net->minor(), 'net');
        self::assertSame($this->minor($vat), $amounts->vat->minor(), 'vat');
        self::assertSame($this->minor($gross), $amounts->gross->minor(), 'gross');
    }

    public function testTheUnitNetIsTheNetPerUnit(): void
    {
        $amounts = $this->calculator()->line($this->line('1000.00', '0.5', 'without', '21'));

        self::assertSame($this->minor('1000.00'), $amounts->unitNet->minor());
        self::assertSame($this->minor('500.00'), $amounts->net->minor());
    }

    public function testTheUnitNetOfAGrossPriceIsTheNetOne(): void
    {
        self::assertSame($this->minor('100.00'), $this->calculator()->line($this->line('121.00', '1', 'with', '21'))->unitNet->minor());
        self::assertSame($this->minor('100.00'), $this->calculator()->line($this->line('121.00', '2', 'with', '21'))->unitNet->minor());
    }

    public function testTheUnitNetIsZeroForZeroQuantity(): void
    {
        $amounts = $this->calculator()->line($this->line('100.00', '0', 'without', '21'));

        self::assertTrue($amounts->unitNet->isZero());
        self::assertTrue($amounts->net->isZero());
        self::assertTrue($amounts->gross->isZero());
    }

    public function testALineWithoutARateHasNoVatAndAGrossPriceReadsAsNet(): void
    {
        $amounts = $this->calculator()->line(new LineValues(
            Money::of('121.00', Currency::CZK),
            Quantity::fromString('1'),
            PriceType::WithVat,
            TaxTreatment::Standard,
            null,
            Percent::fromString('0'),
        ));

        self::assertSame($this->minor('121.00'), $amounts->net->minor());
        self::assertTrue($amounts->vat->isZero());
        self::assertSame($this->minor('121.00'), $amounts->gross->minor());
    }

    public function testTotalsRoundToAWholeCrownOutsideTheBase(): void
    {
        $totals = $this->totals([$this->line('100833.00', '1', 'without', '21')]);

        self::assertCount(1, $totals->summaries);
        self::assertSame($this->minor('100833.00'), $totals->summaries[0]->base->minor());
        self::assertSame($this->minor('21174.93'), $totals->summaries[0]->vat->minor());
        self::assertSame($this->minor('122007.93'), $totals->gross->minor());
        self::assertSame($this->minor('0.07'), $totals->rounding->minor());
        self::assertSame($this->minor('122008.00'), $totals->payable->minor());
    }

    /**
     * @return iterable<string, array{LineValues, string, string, string}>
     */
    public static function roundedTotals(): iterable
    {
        yield 'down by 0.40' => [self::lineOf('1440.00', 'without', '21'), '1742.40', '-0.40', '1742.00'];
        yield 'up by 0.40' => [self::lineOf('1260.00', 'without', '21'), '1524.60', '0.40', '1525.00'];
        yield 'up by 0.11 from a gross price' => [self::lineOf('97511.89', 'with', '21'), '97511.89', '0.11', '97512.00'];
        yield 'already whole' => [self::lineOf('100.00', 'without', '21'), '121.00', '0.00', '121.00'];
    }

    #[DataProvider('roundedTotals')]
    public function testRoundingStaysOutsideBaseAndVat(LineValues $line, string $gross, string $rounding, string $payable): void
    {
        $totals = $this->totals([$line]);
        $unrounded = $this->totals([$line], rounding: TotalRounding::None);

        self::assertSame($this->minor($gross), $totals->gross->minor());
        self::assertSame($this->minor($rounding), $totals->rounding->minor());
        self::assertSame($this->minor($payable), $totals->payable->minor());
        self::assertEquals($unrounded->summaries, $totals->summaries);
        self::assertSame($unrounded->net->minor(), $totals->net->minor());
        self::assertSame($unrounded->vat->minor(), $totals->vat->minor());
        self::assertSame($unrounded->gross->minor(), $totals->gross->minor());
        self::assertSame($totals->gross->minor(), $totals->net->plus($totals->vat)->minor());
    }

    public function testExactlyFiftyHellersRoundUp(): void
    {
        $totals = $this->totals([$this->line('100.50', '1', 'base', '21')]);

        self::assertSame($this->minor('0.50'), $totals->rounding->minor());
        self::assertSame($this->minor('101.00'), $totals->payable->minor());
    }

    public function testRoundingFollowsThePolicy(): void
    {
        $line = $this->line('100833.00', '1', 'without', '21');

        $none = $this->totals([$line], rounding: TotalRounding::None);
        $whole = $this->totals([$line], rounding: TotalRounding::WholeUnit);

        self::assertTrue($none->rounding->isZero());
        self::assertSame($this->minor('122007.93'), $none->payable->minor());
        self::assertSame($this->minor('0.07'), $whole->rounding->minor());
    }

    public function testMixedRatesGetOneSummaryEach(): void
    {
        $totals = $this->totals([
            $this->line('100.00', '1', 'without', '12'),
            $this->line('200.00', '1', 'without', '21'),
            $this->line('50.00', '2', 'without', '21'),
            $this->line('121.00', '1', 'with', '21'),
        ], rounding: TotalRounding::None);

        self::assertCount(2, $totals->summaries);
        [$base, $reduced] = $totals->summaries;
        self::assertSame('21.00', $base->rate?->decimal());
        self::assertSame($this->minor('400.00'), $base->base->minor());
        self::assertSame($this->minor('84.00'), $base->vat->minor());
        self::assertSame($this->minor('484.00'), $base->gross->minor());
        self::assertSame('12.00', $reduced->rate?->decimal());
        self::assertSame($this->minor('100.00'), $reduced->base->minor());
        self::assertSame($this->minor('12.00'), $reduced->vat->minor());
        self::assertSame($this->minor('112.00'), $reduced->gross->minor());
        self::assertSame($this->minor('500.00'), $totals->net->minor());
        self::assertSame($this->minor('96.00'), $totals->vat->minor());
        self::assertSame($this->minor('596.00'), $totals->gross->minor());
    }

    public function testSummariesDoNotDependOnTheOrderOfTheLines(): void
    {
        $a = $this->line('100.00', '1', 'without', '12');
        $b = $this->line('200.00', '1', 'without', '21');

        self::assertEquals($this->totals([$a, $b])->summaries, $this->totals([$b, $a])->summaries);
    }

    public function testEachLineIsRoundedOnItsOwnBeforeItIsSummed(): void
    {
        $lines = [
            $this->line('0.50', '1', 'without', '21'),
            $this->line('0.50', '1', 'without', '21'),
            $this->line('0.50', '1', 'without', '21'),
        ];

        $totals = $this->totals($lines, rounding: TotalRounding::None);

        // 0.50 x 0.21 = 0.105 is 0.11 for each line, 0.33 together; summing first (1.50 x 0.21 = 0.315) would give 0.32.
        self::assertSame($this->minor('0.33'), $totals->vat->minor());
        self::assertSame($this->minor('1.50'), $totals->net->minor());
    }

    public function testReverseChargeCarriesNoRateAndNoVat(): void
    {
        $totals = $this->totals([new LineValues(
            Money::of('1000.00', Currency::CZK),
            Quantity::fromString('1'),
            PriceType::WithoutVat,
            TaxTreatment::ReverseCharge,
            Percent::fromString('21'),
            Percent::fromString('0'),
        )]);

        self::assertCount(1, $totals->summaries);
        self::assertSame(TaxTreatment::ReverseCharge, $totals->summaries[0]->treatment);
        self::assertNull($totals->summaries[0]->rate);
        self::assertSame($this->minor('1000.00'), $totals->summaries[0]->base->minor());
        self::assertTrue($totals->summaries[0]->vat->isZero());
        self::assertSame($this->minor('1000.00'), $totals->gross->minor());
        self::assertSame($this->minor('1000.00'), $totals->payable->minor());
    }

    public function testStandardAndReverseChargeAreSeparateSummaries(): void
    {
        $totals = $this->totals([
            $this->line('100.00', '1', 'without', '21'),
            new LineValues(Money::of('300.00', Currency::CZK), Quantity::fromString('1'), PriceType::WithoutVat, TaxTreatment::ReverseCharge, null, Percent::fromString('0')),
            new LineValues(Money::of('50.00', Currency::CZK), Quantity::fromString('1'), PriceType::WithoutVat, TaxTreatment::Exempt, null, Percent::fromString('0')),
        ]);

        self::assertSame(
            [TaxTreatment::Standard, TaxTreatment::ReverseCharge, TaxTreatment::Exempt],
            array_map(static fn (RateSummary $summary): TaxTreatment => $summary->treatment, $totals->summaries),
        );
        self::assertSame($this->minor('450.00'), $totals->net->minor());
        self::assertSame($this->minor('21.00'), $totals->vat->minor());
    }

    public function testBaseOnlyKeepsTheRateWithNoVat(): void
    {
        $totals = $this->totals([
            $this->line('100.00', '1', 'base', '21'),
            $this->line('100.00', '1', 'without', '21'),
        ], rounding: TotalRounding::None);

        self::assertCount(1, $totals->summaries);
        self::assertSame('21.00', $totals->summaries[0]->rate?->decimal());
        self::assertSame($this->minor('200.00'), $totals->summaries[0]->base->minor());
        self::assertSame($this->minor('21.00'), $totals->summaries[0]->vat->minor());
    }

    public function testANonPayerHasNoVatWhateverTheLinesSay(): void
    {
        $totals = $this->totals([
            $this->line('121.00', '1', 'with', '21'),
            $this->line('100.00', '1', 'without', '12'),
        ], regime: VatRegime::NonPayer);

        self::assertTrue($totals->vat->isZero());
        self::assertSame($this->minor('221.00'), $totals->net->minor());
        self::assertSame($this->minor('221.00'), $totals->gross->minor());
        foreach ($totals->summaries as $summary) {
            self::assertNull($summary->rate);
            self::assertTrue($summary->vat->isZero());
        }
        self::assertCount(1, $totals->summaries);
    }

    public function testForeignCurrencyIsNotRounded(): void
    {
        $line = new LineValues(Money::of('100833.00', Currency::EUR), Quantity::fromString('1'), PriceType::WithoutVat, TaxTreatment::Standard, Percent::fromString('21'), Percent::fromString('0'));

        $totals = $this->totals([$line]);

        self::assertTrue($totals->rounding->isZero());
        self::assertSame(Currency::EUR, $totals->rounding->currency());
        self::assertSame($totals->gross->minor(), $totals->payable->minor());
        self::assertSame($this->minor('122007.93'), $totals->payable->minor());
    }

    public function testNoLinesGivesZero(): void
    {
        $totals = $this->totals([]);

        self::assertSame([], $totals->summaries);
        foreach ([$totals->net, $totals->vat, $totals->gross, $totals->rounding, $totals->payable] as $amount) {
            self::assertTrue($amount->isZero());
            self::assertSame(Currency::CZK, $amount->currency());
        }
    }

    public function testLinesInTwoCurrenciesAreRefused(): void
    {
        $this->expectException(\App\Shared\Domain\CurrencyMismatch::class);

        $this->totals([
            $this->line('1.00', '1', 'without', '21'),
            new LineValues(Money::of('1.00', Currency::EUR), Quantity::fromString('1'), PriceType::WithoutVat, TaxTreatment::Standard, Percent::fromString('21'), Percent::fromString('0')),
        ]);
    }

    private function calculator(): InvoiceCalculator
    {
        return new InvoiceCalculator();
    }

    /**
     * @param list<LineValues> $lines
     */
    private function totals(array $lines, VatRegime $regime = VatRegime::Payer, TotalRounding $rounding = TotalRounding::WholeUnit): DocumentTotals
    {
        return $this->calculator()->totals($lines, $regime, $rounding, Currency::CZK, true);
    }

    private function line(string $price, string $quantity, string $type, string $rate, string $discount = '0'): LineValues
    {
        return self::lineOf($price, $type, $rate, $quantity, $discount);
    }

    private static function lineOf(string $price, string $type, string $rate, string $quantity = '1', string $discount = '0'): LineValues
    {
        return new LineValues(
            Money::of($price, Currency::CZK),
            Quantity::fromString($quantity),
            match ($type) {
                'with' => PriceType::WithVat,
                'base' => PriceType::BaseOnly,
                default => PriceType::WithoutVat,
            },
            TaxTreatment::Standard,
            Percent::fromString($rate),
            Percent::fromString($discount),
        );
    }

    private function minor(string $decimal): int
    {
        return Money::of($decimal, Currency::CZK)->minor();
    }

    public function testSummariesFollowThePrintOrderAndTheHigherRateFirst(): void
    {
        $line = static fn (TaxTreatment $treatment, ?string $rate): LineValues => new LineValues(Money::of('100', Currency::CZK), Quantity::fromString('1'), PriceType::WithoutVat, $treatment, null === $rate ? null : Percent::fromString($rate), Percent::fromString('0'));

        $totals = new InvoiceCalculator()->totals([
            $line(TaxTreatment::NotApplicable, null),
            $line(TaxTreatment::Exempt, null),
            $line(TaxTreatment::ReverseCharge, null),
            $line(TaxTreatment::Standard, '12'),
            $line(TaxTreatment::Standard, '21'),
        ], VatRegime::Payer, TotalRounding::None, Currency::CZK, true);

        self::assertSame(
            [['standard', '21.00'], ['standard', '12.00'], ['reverse_charge', null], ['exempt', null], ['not_applicable', null]],
            array_map(static fn (RateSummary $summary): array => [$summary->treatment->value, $summary->rate?->decimal()], $totals->summaries),
        );
    }

    public function testTheSmallestAndNegativeQuantitiesAreExact(): void
    {
        $line = static fn (string $quantity): LineValues => new LineValues(Money::of('1000000', Currency::CZK), Quantity::fromString($quantity), PriceType::BaseOnly, TaxTreatment::Exempt, null, Percent::fromString('0'));
        $calculator = new InvoiceCalculator();

        self::assertSame(10000, $calculator->line($line('0.0001'))->net->minor());
        self::assertSame(-200000000, $calculator->line($line('-2'))->net->minor());
        self::assertSame(1234500000000, $calculator->line($line('12345'))->net->minor());
        self::assertSame(0, $calculator->line($line('0'))->net->minor());
    }
}
