<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\TaxTreatment;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The VAT of tax documents per treatment and rate, from the rate summaries of their
 * stored lines, in the home currency: each summary is converted at the rate of its
 * document. An advance invoice is no tax document and adds nothing.
 */
final class VatRecap
{
    /**
     * @param iterable<IssuedDocument> $documents
     *
     * @return list<VatRecapLine> in the order of the treatments, a higher rate first
     */
    public static function of(iterable $documents, Currency $home): array
    {
        /** @var array<string, VatRecapLine> $lines */
        $lines = [];
        foreach ($documents as $document) {
            if (!$document->kind->isTaxDocument()) {
                continue;
            }
            foreach ($document->summaries as $summary) {
                $key = implode('|', [$summary->treatment->value, $summary->rate?->basisPoints()]);
                $base = self::converted($summary->base, $document->exchangeRate, $home);
                $vat = self::converted($summary->vat, $document->exchangeRate, $home);
                $gross = self::converted($summary->gross, $document->exchangeRate, $home);
                $line = $lines[$key] ?? null;
                $lines[$key] = null === $line
                    ? new VatRecapLine($summary->treatment, $summary->rate, $base, $vat, $gross)
                    : new VatRecapLine($line->treatment, $line->rate, $line->base->plus($base), $line->vat->plus($vat), $line->gross->plus($gross));
            }
        }
        $order = array_flip(array_map(static fn (TaxTreatment $treatment): string => $treatment->value, TaxTreatment::cases()));
        // A higher rate first, so the rates are compared the other way round; a line without one comes after them all.
        usort($lines, static fn (VatRecapLine $one, VatRecapLine $two): int => [$order[$one->treatment->value], $two->rate?->basisPoints() ?? \PHP_INT_MIN] <=> [$order[$two->treatment->value], $one->rate?->basisPoints() ?? \PHP_INT_MIN]);

        return $lines;
    }

    /**
     * @param list<VatRecapLine> $lines
     */
    public static function total(array $lines, Currency $home): VatRecapLine
    {
        $zero = Money::ofMinor(0, $home);
        $total = new VatRecapLine(TaxTreatment::Standard, null, $zero, $zero, $zero);
        foreach ($lines as $line) {
            $total = new VatRecapLine($total->treatment, null, $total->base->plus($line->base), $total->vat->plus($line->vat), $total->gross->plus($line->gross));
        }

        return $total;
    }

    private static function converted(Money $amount, string $rate, Currency $home): Money
    {
        return Money::ofMinor($amount->minor(), $home)->multipliedBy($rate);
    }
}
