<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Shared\Domain\Money;

/**
 * The lines of a tax document on payment: the amount received, split over the
 * groups of the advance invoice that share a treatment, a rate and a supply code,
 * in the proportion of their gross amounts (GFR on §20a, example 7). Each line is
 * priced with VAT, so the VAT comes out of the gross (§37). The split loses and
 * gains no minor unit; what rounding leaves goes to the first groups. A group that
 * adds up to nothing or less, a discount at a rate of its own, takes no share.
 */
final readonly class PaymentTaxDocumentLines
{
    /**
     * @param list<InvoiceLine> $advanceLines
     *
     * @return list<LineInput> one per group, in the order of the advance; none when the advance has nothing to split
     */
    public function of(array $advanceLines, Money $received, string $text): array
    {
        /** @var array<string, list<InvoiceLine>> $grouped */
        $grouped = [];
        foreach ($advanceLines as $line) {
            $grouped[implode('|', [$line->treatment()->value, $line->rateKind()?->value, $line->supplyCode()])][] = $line;
        }
        $sources = [];
        $ratios = [];
        foreach ($grouped as $lines) {
            $gross = array_sum(array_map(static fn (InvoiceLine $line): int => $line->gross()->minor(), $lines));
            if ($gross > 0) {
                $sources[] = $lines[0];
                $ratios[] = $gross;
            }
        }
        if ([] === $ratios) {
            return [];
        }

        return array_map(
            static fn (InvoiceLine $source, Money $share): LineInput => new LineInput($text, Quantity::fromString('1'), '', $share, PriceType::WithVat, $source->treatment(), $source->rateKind(), $source->supplyCode(), Percent::fromString('0')),
            $sources,
            $received->allocate(...$ratios),
        );
    }
}
