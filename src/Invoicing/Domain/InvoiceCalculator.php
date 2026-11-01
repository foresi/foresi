<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Prices a line and adds a document up, in exact money.
 *
 * VAT is not rounded to crowns (since 1 April 2019): from a net price it is the net times the
 * rate, rounded to the haler; from a gross price the net is the gross divided by 1 plus the rate,
 * rounded to the haler, and the VAT is what is left (VAT Act §37). The rounding of the total
 * (§36(5)) is a CZK-only amount of its own, outside every base and VAT.
 */
final readonly class InvoiceCalculator
{
    public function line(LineValues $values): LineAmounts
    {
        // One rounding for price, quantity and discount together: the factor is exact.
        $amount = $values->price->multipliedBy($this->factor($values->quantity, $values->discount));
        $rate = $values->treatment->hasRate() ? $values->rate : null;

        if (PriceType::WithVat === $values->priceType && $rate instanceof Percent) {
            $net = $amount->dividedBy($rate->inclusiveFactor());
            $vat = $amount->minus($net);
        } elseif (PriceType::WithoutVat === $values->priceType && $rate instanceof Percent) {
            $net = $amount;
            $vat = $net->multipliedBy($rate->factorOf());
        } else {
            // A base only, or no rate: the amount is the net and there is no VAT.
            $net = $amount;
            $vat = Money::ofMinor(0, $amount->currency());
        }

        return new LineAmounts(
            $values->quantity->isZero() ? Money::ofMinor(0, $amount->currency()) : $net->dividedBy($values->quantity->toDecimal()),
            $net,
            $vat,
            $net->plus($vat),
        );
    }

    /**
     * @param list<LineValues> $lines
     *
     * @throws \App\Shared\Domain\CurrencyMismatch when the lines are priced in more than one currency
     */
    public function totals(array $lines, VatRegime $regime, TotalRounding $rounding, Currency $home): DocumentTotals
    {
        $currency = [] === $lines ? $home : $lines[0]->price->currency();
        $zero = Money::ofMinor(0, $currency);

        /** @var array<string, array{treatment: TaxTreatment, rate: ?Percent, base: Money, vat: Money, gross: Money}> $groups */
        $groups = [];
        foreach ($lines as $values) {
            if (VatRegime::NonPayer === $regime) {
                $values = new LineValues($values->price, $values->quantity, $values->priceType, $values->treatment, null, $values->discount);
            }
            $amounts = $this->line($values);
            $rate = $values->treatment->hasRate() ? $values->rate : null;
            $key = $values->treatment->value.'|'.($rate instanceof Percent ? $rate->basisPoints() : '-');
            $group = $groups[$key] ?? ['treatment' => $values->treatment, 'rate' => $rate, 'base' => $zero, 'vat' => $zero, 'gross' => $zero];
            $groups[$key] = [
                'treatment' => $values->treatment,
                'rate' => $rate,
                'base' => $group['base']->plus($amounts->net),
                'vat' => $group['vat']->plus($amounts->vat),
                'gross' => $group['gross']->plus($amounts->gross),
            ];
        }

        $summaries = array_map(
            static fn (array $group): RateSummary => new RateSummary($group['treatment'], $group['rate'], $group['base'], $group['vat'], $group['gross']),
            array_values($groups),
        );
        usort($summaries, static fn (RateSummary $a, RateSummary $b): int => [self::order($a->treatment), -($a->rate?->basisPoints() ?? 0)] <=> [self::order($b->treatment), -($b->rate?->basisPoints() ?? 0)]);

        $net = $zero;
        $vat = $zero;
        $gross = $zero;
        foreach ($summaries as $summary) {
            $net = $net->plus($summary->base);
            $vat = $vat->plus($summary->vat);
            $gross = $gross->plus($summary->gross);
        }

        // Only a crown amount in the home currency is rounded to a whole crown.
        $roundsToWhole = TotalRounding::WholeUnit === $rounding && Currency::CZK === $currency && $home === $currency;
        $difference = $roundsToWhole ? $gross->roundedToWholeUnit()->minus($gross) : $zero;

        return new DocumentTotals($summaries, $net, $vat, $gross, $difference, $gross->plus($difference));
    }

    /**
     * quantity x (1 - discount) as an exact decimal.
     */
    private function factor(Quantity $quantity, Percent $discount): string
    {
        // Ten-thousandths times ten-thousandths: eight decimals, and the quantity bound keeps it in 64 bits.
        $product = $quantity->tenThousandths() * (10000 - $discount->basisPoints());

        return $this->decimal($product, 8);
    }

    private static function order(TaxTreatment $treatment): int
    {
        return match ($treatment) {
            TaxTreatment::Standard => 0,
            TaxTreatment::ReverseCharge => 1,
            TaxTreatment::Exempt => 2,
            TaxTreatment::NotApplicable => 3,
        };
    }

    private function decimal(int $scaled, int $scale): string
    {
        $absolute = abs($scaled);
        $text = str_pad((string) $absolute, $scale + 1, '0', \STR_PAD_LEFT);

        return ($scaled < 0 ? '-' : '').substr($text, 0, -$scale).'.'.substr($text, -$scale);
    }
}
