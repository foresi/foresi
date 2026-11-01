<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentTotals;
use App\Invoicing\Domain\InvoiceCalculator;
use App\Invoicing\Domain\LineValues;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Shared\Domain\Currency;

/**
 * Turns typed lines into figures, the one way every path does it, the recount of
 * the form included. The regime comes first: a non-payer's price is the amount,
 * whatever was typed, so a line never shows VAT its totals do not have. A rate
 * comes from the kind on the calendar date of the tax point.
 */
final readonly class LinePricing
{
    public function __construct(private InvoiceCalculator $calculator)
    {
    }

    /**
     * What is left of a line under a regime: a non-payer's line has no VAT at all,
     * and a value that does not belong to the treatment does not stay.
     */
    public function normalised(LineInput $line, VatRegime $regime): LineInput
    {
        $nonPayer = VatRegime::NonPayer === $regime;
        $treatment = $nonPayer ? TaxTreatment::NotApplicable : $line->treatment;

        return new LineInput(
            trim($line->text),
            $line->quantity,
            $line->unit,
            $line->price,
            $nonPayer ? PriceType::WithoutVat : $line->priceType,
            $treatment,
            $treatment->hasRate() ? $line->rateKind : null,
            TaxTreatment::ReverseCharge === $treatment ? $line->supplyCode : null,
            $line->discount,
        );
    }

    /**
     * @param list<LineInput> $lines
     *
     * @return list<PricedLine>
     *
     * @throws \DomainException   when the country has no rate on that date
     * @throws \OverflowException when an amount does not fit
     */
    public function priced(array $lines, VatRegime $regime, InvoicingCountry $country, \DateTimeImmutable $taxPoint): array
    {
        return array_map(function (LineInput $line) use ($regime, $country, $taxPoint): PricedLine {
            $line = $this->normalised($line, $regime);
            $rate = $this->rate($line, $country, $taxPoint);
            $values = new LineValues($line->price, $line->quantity, $line->priceType, $line->treatment, $rate, $line->discount);

            return new PricedLine($line, $values, $this->calculator->line($values));
        }, $lines);
    }

    /**
     * @param list<PricedLine> $lines
     *
     * @throws \OverflowException when an amount does not fit
     */
    public function totals(array $lines, VatRegime $regime, TotalRounding $rounding, Currency $home): DocumentTotals
    {
        return $this->calculator->totals(array_map(static fn (PricedLine $line): LineValues => $line->values, $lines), $regime, $rounding, $home);
    }

    private function rate(LineInput $line, InvoicingCountry $country, \DateTimeImmutable $taxPoint): ?Percent
    {
        return $line->rateKind instanceof VatRateKind ? $country->rate($line->rateKind, $taxPoint) : null;
    }
}
