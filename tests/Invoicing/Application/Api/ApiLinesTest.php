<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Api;

use Foresi\Invoicing\Application\Api\ApiLines;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lines of the API: an amount written with the places of its currency, and
 * every VAT of a line read and answered under the same name, so a line read
 * back can be sent back.
 */
final class ApiLinesTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'a few hellers' => [5, '0.05'];
        yield 'under one crown' => [50, '0.50'];
        yield 'whole crowns' => [150000, '1500.00'];
        yield 'a credit' => [-5, '-0.05'];
        yield 'a larger credit' => [-123456, '-1234.56'];
    }

    #[DataProvider('amounts')]
    public function testAnAmountIsADecimalOfThePlacesOfItsCurrency(int $minor, string $decimal): void
    {
        self::assertSame($decimal, ApiLines::decimal(Money::ofMinor($minor, Currency::of('CZK'))));
    }

    /**
     * @return iterable<string, array{string, TaxTreatment, ?VatRateKind}>
     */
    public static function vats(): iterable
    {
        yield 'base' => ['base', TaxTreatment::Standard, VatRateKind::Base];
        yield 'reduced' => ['reduced', TaxTreatment::Standard, VatRateKind::Reduced];
        yield 'second reduced' => ['second_reduced', TaxTreatment::Standard, VatRateKind::SecondReduced];
        yield 'reverse charge' => ['reverse_charge', TaxTreatment::ReverseCharge, null];
        yield 'exempt' => ['exempt', TaxTreatment::Exempt, null];
        yield 'none' => ['none', TaxTreatment::NotApplicable, null];
    }

    #[DataProvider('vats')]
    public function testEveryVatIsReadAndAnsweredUnderOneName(string $vat, TaxTreatment $treatment, ?VatRateKind $rateKind): void
    {
        $lines = ApiLines::read([['text' => 'Hosting', 'unit_price' => '1000.00', 'vat' => $vat]], Currency::of('CZK'), VatRegime::Payer, false);

        self::assertCount(1, $lines);
        self::assertSame([$treatment, $rateKind], [$lines[0]->treatment, $lines[0]->rateKind]);

        $fields = ApiLines::fields('Hosting', Quantity::fromString('1'), 'month', Money::of('1000.00', Currency::of('CZK')), PriceType::WithoutVat, $treatment, $rateKind, null, Percent::fromString('0'));
        self::assertSame($vat, $fields['vat']);
    }

    public function testAStandardLineWithoutARateIsAnsweredAsTheBaseRate(): void
    {
        $fields = ApiLines::fields('Hosting', Quantity::fromString('1'), 'month', Money::of('1000.00', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, null, null, Percent::fromString('0'));

        self::assertSame('base', $fields['vat']);
    }

    public function testTheIdsOfALineAreIgnoredAndNeverSetFromInput(): void
    {
        $fields = ApiLines::fields('Hosting', Quantity::fromString('1'), 'month', Money::of('1000.00', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, null, null, Percent::fromString('0'));

        $lines = ApiLines::read([['id' => '0197a1de-0000-7000-8000-000000000001', 'source_line_id' => '0197a1de-0000-7000-8000-000000000002'] + $fields], Currency::of('CZK'), VatRegime::Payer, false);

        self::assertCount(1, $lines);
        self::assertSame('Hosting', $lines[0]->text);
    }
}
