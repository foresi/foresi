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
 * VAT is rounded to the minor unit only: from a net price it is the net times the rate; from a
 * gross price the net is the gross divided by 1 plus the rate, and the VAT is what is left. The
 * rounding of the total is an amount of its own, outside every base and VAT, where the country
 * of the document has one.
 */
final readonly class InvoiceCalculator
{
    private const array PRINT_ORDER = [TaxTreatment::Standard, TaxTreatment::ReverseCharge, TaxTreatment::Exempt, TaxTreatment::NotApplicable];
    /** Ten-thousandths times ten-thousandths. */
    private const int FACTOR_UNIT = 100_000_000;

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
     * @param bool             $homeRounds whether the country rounds a total in its home currency
     *
     * @throws \App\Shared\Domain\CurrencyMismatch when the lines are priced in more than one currency
     */
    public function totals(array $lines, VatRegime $regime, TotalRounding $rounding, Currency $home, bool $homeRounds): DocumentTotals
    {
        $currency = [] === $lines ? $home : $lines[0]->price->currency();
        $zero = Money::ofMinor(0, $currency);
        $priced = [];
        foreach ($lines as $values) {
            if (VatRegime::NonPayer === $regime) {
                $values = new LineValues($values->price, $values->quantity, $values->priceType, $values->treatment, null, $values->discount);
            }
            $priced[] = new SummaryLine($values->treatment, $values->rate, $this->line($values));
        }
        $summaries = $this->summaries($priced, $zero);
        $net = $zero;
        $vat = $zero;
        $gross = $zero;
        foreach ($summaries as $summary) {
            $net = $net->plus($summary->base);
            $vat = $vat->plus($summary->vat);
            $gross = $gross->plus($summary->gross);
        }

        // A total is rounded only where the country does so, and only in its home currency.
        $roundsToWhole = TotalRounding::WholeUnit === $rounding && $homeRounds && $home === $currency;
        $difference = $roundsToWhole ? $gross->roundedToWholeUnit()->minus($gross) : $zero;

        return new DocumentTotals($summaries, $net, $vat, $gross, $difference, $gross->plus($difference));
    }

    /**
     * Adds up lines that already have their amounts, per treatment and rate, in the
     * order a document prints them. It calculates nothing else, so an issued document
     * can show what is stored.
     *
     * @param list<SummaryLine> $lines
     *
     * @return list<RateSummary>
     */
    public function summaries(array $lines, Money $zero): array
    {
        /** @var array<string, array{treatment: TaxTreatment, rate: ?Percent, base: Money, vat: Money, gross: Money}> $groups */
        $groups = [];
        foreach ($lines as $line) {
            $rate = $line->treatment->hasRate() ? $line->rate : null;
            $key = $line->treatment->value.'|'.($rate instanceof Percent ? $rate->basisPoints() : '-');
            $sums = $groups[$key] ?? ['base' => $zero, 'vat' => $zero, 'gross' => $zero];
            $groups[$key] = [
                'treatment' => $line->treatment,
                'rate' => $rate,
                'base' => $sums['base']->plus($line->amounts->net),
                'vat' => $sums['vat']->plus($line->amounts->vat),
                'gross' => $sums['gross']->plus($line->amounts->gross),
            ];
        }
        $summaries = array_map(
            static fn (array $group): RateSummary => new RateSummary($group['treatment'], $group['rate'], $group['base'], $group['vat'], $group['gross']),
            $groups,
        );
        // The treatment in print order, then the higher rate first: $a and $b swap for that column.
        usort($summaries, static fn (RateSummary $a, RateSummary $b): int => [self::order($a->treatment), $b->rate?->basisPoints()] <=> [self::order($b->treatment), $a->rate?->basisPoints()]);

        return $summaries;
    }

    /**
     * quantity x (1 - discount) as an exact decimal.
     */
    private function factor(Quantity $quantity, Percent $discount): string
    {
        // Ten-thousandths times ten-thousandths: eight decimals, and the quantity bound keeps it in 64 bits.
        $product = $quantity->tenThousandths() * (10000 - $discount->basisPoints());

        return $this->decimal($product);
    }

    private static function order(TaxTreatment $treatment): int|false
    {
        return array_search($treatment, self::PRINT_ORDER, true);
    }

    private function decimal(int $scaled): string
    {
        $absolute = abs($scaled);

        return \sprintf('%s%d.%08d', $scaled < 0 ? '-' : '', intdiv($absolute, self::FACTOR_UNIT), $absolute % self::FACTOR_UNIT);
    }
}
