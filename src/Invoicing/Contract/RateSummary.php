<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The lines of one treatment and rate added up. A treatment without a rate has none here.
 */
final readonly class RateSummary
{
    private const array PRINT_ORDER = [TaxTreatment::Standard, TaxTreatment::ReverseCharge, TaxTreatment::Exempt, TaxTreatment::NotApplicable];

    public function __construct(
        public TaxTreatment $treatment,
        public ?Percent $rate,
        public Money $base,
        public Money $vat,
        public Money $gross,
    ) {
    }

    /**
     * Adds up lines that already have their amounts, per treatment and rate, in the
     * order a document prints them. It calculates nothing else, so an issued document
     * can show what is stored.
     *
     * @param list<SummaryLine> $lines
     *
     * @return list<self>
     */
    public static function of(array $lines, Money $zero): array
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
            static fn (array $group): self => new self($group['treatment'], $group['rate'], $group['base'], $group['vat'], $group['gross']),
            $groups,
        );
        // The treatment in print order, then the higher rate first: $a and $b swap for that column.
        usort($summaries, static fn (self $a, self $b): int => [self::order($a->treatment), $b->rate?->basisPoints()] <=> [self::order($b->treatment), $a->rate?->basisPoints()]);

        return $summaries;
    }

    private static function order(TaxTreatment $treatment): int|false
    {
        return array_search($treatment, self::PRINT_ORDER, true);
    }
}
