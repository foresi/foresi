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
        /** @var array<string, array{line: InvoiceLine, gross: int}> $groups */
        $groups = [];
        foreach ($advanceLines as $line) {
            $key = $line->treatment()->value.'|'.($line->rateKind()->value ?? '-').'|'.($line->supplyCode() ?? '-');
            $groups[$key] ??= ['line' => $line, 'gross' => 0];
            $groups[$key]['gross'] += $line->gross()->minor();
        }
        $groups = array_values(array_filter($groups, static fn (array $group): bool => 0 < $group['gross']));
        if ([] === $groups) {
            return [];
        }
        $shares = $received->allocate(...array_map(static fn (array $group): int => $group['gross'], $groups));

        return array_map(static function (array $group, Money $share) use ($text): LineInput {
            $source = $group['line'];

            return new LineInput($text, Quantity::fromString('1'), '', $share, PriceType::WithVat, $source->treatment(), $source->rateKind(), $source->supplyCode(), Percent::fromString('0'));
        }, $groups, $shares);
    }
}
