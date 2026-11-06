<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\VatRecap;
use App\Invoicing\Application\VatRecapLine;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class VatRecapTest extends TestCase
{
    public function testAddsUpEachTreatmentAndRateInTheHomeCurrencyInTheOrderOfTheTreatmentsAHigherRateFirst(): void
    {
        $lines = VatRecap::of([
            // An advance is no tax document, and one before the invoices stops none of them.
            $this->document(DocumentKind::Advance, Currency::CZK, '1', [$this->summary(TaxTreatment::Standard, '21.00', '999.00', '209.79', '1208.79')]),
            $this->document(DocumentKind::Invoice, Currency::EUR, '25', [
                $this->summary(TaxTreatment::Exempt, null, '100.00', '0.00', '100.00'),
                $this->summary(TaxTreatment::Standard, '12.00', '100.00', '12.00', '112.00'),
            ]),
            $this->document(DocumentKind::Invoice, Currency::CZK, '1', [
                $this->summary(TaxTreatment::NotApplicable, null, '50.00', '0.00', '50.00'),
                $this->summary(TaxTreatment::ReverseCharge, null, '200.00', '0.00', '200.00'),
                $this->summary(TaxTreatment::Standard, null, '40.00', '0.00', '40.00'),
                $this->summary(TaxTreatment::Standard, '0.00', '300.00', '0.00', '300.00'),
                $this->summary(TaxTreatment::Standard, '12.00', '10.00', '1.20', '11.20'),
                $this->summary(TaxTreatment::Standard, '21.00', '1000.00', '210.00', '1210.00'),
            ]),
            $this->document(DocumentKind::CorrectionDocument, Currency::CZK, '1', [$this->summary(TaxTreatment::Standard, '21.00', '500.00', '105.00', '605.00')]),
        ], Currency::CZK);

        self::assertSame([
            ['standard', 2100, 'CZK', 150000, 31500, 181500],
            ['standard', 1200, 'CZK', 251000, 30120, 281120],
            ['standard', 0, 'CZK', 30000, 0, 30000],
            ['standard', null, 'CZK', 4000, 0, 4000],
            ['reverse_charge', null, 'CZK', 20000, 0, 20000],
            ['exempt', null, 'CZK', 250000, 0, 250000],
            ['not_applicable', null, 'CZK', 5000, 0, 5000],
        ], array_map($this->row(...), $lines));
        self::assertSame(['standard', null, 'CZK', 710000, 61620, 771620], $this->row(VatRecap::total($lines, Currency::CZK)));
    }

    public function testNothingTaxedGivesNoLineAndAZeroTotal(): void
    {
        $lines = VatRecap::of([$this->document(DocumentKind::Advance, Currency::CZK, '1', [$this->summary(TaxTreatment::Standard, '21.00', '100.00', '21.00', '121.00')])], Currency::EUR);

        self::assertSame([], $lines);
        self::assertSame(['standard', null, 'EUR', 0, 0, 0], $this->row(VatRecap::total($lines, Currency::EUR)));
    }

    /**
     * @return array{0: string, 1: ?int, 2: string, 3: int, 4: int, 5: int}
     */
    private function row(VatRecapLine $line): array
    {
        return [$line->treatment->value, $line->rate?->basisPoints(), $line->base->currency()->value, $line->base->minor(), $line->vat->minor(), $line->gross->minor()];
    }

    private function summary(TaxTreatment $treatment, ?string $rate, string $base, string $vat, string $gross): RateSummary
    {
        return new RateSummary($treatment, null === $rate ? null : Percent::fromString($rate), Money::of($base, Currency::CZK), Money::of($vat, Currency::CZK), Money::of($gross, Currency::CZK));
    }

    /**
     * @param list<RateSummary> $summaries in CZK, read as amounts of the document's currency
     */
    private function document(DocumentKind $kind, Currency $currency, string $exchangeRate, array $summaries): IssuedDocument
    {
        $zero = Money::ofMinor(0, $currency);
        $party = new DocumentParty('Example Customer a.s.', '87654320', 'CZ87654320', new Address('Street 1', 'City', '100 00', 'CZ'), '', null, '', '', null, null);
        $summaries = array_map(static fn (RateSummary $summary): RateSummary => new RateSummary($summary->treatment, $summary->rate, Money::ofMinor($summary->base->minor(), $currency), Money::ofMinor($summary->vat->minor(), $currency), Money::ofMinor($summary->gross->minor(), $currency)), $summaries);

        return new IssuedDocument('019a0000-0000-7000-8000-000000000001', $kind, '20260001', VatRegime::Payer, TotalRounding::WholeUnit, $currency, $exchangeRate, $party, $party, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, null, '20260001', '', '', '', '', '', DocumentLanguage::Czech, [], $summaries, $zero, $zero, $zero, $zero, $zero);
    }
}
