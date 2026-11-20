<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The actual income and costs paid from $from to $to, in CZK, as the
 * estimate reads them (B2, B3): the sums by month, kind and source, the
 * totals of each kind, and the amounts themselves of the sources whose
 * documents the caller may read. A source that is not installed or failed is
 * listed in $unavailableSources, one whose documents the caller may not read
 * in $restrictedSources, and a currency without a rate on a payment day in
 * $missingRates; their amounts are left out.
 */
final readonly class AmountsView
{
    public const string UNVERIFIED_FX_PAYMENT_DAY = 'fx_payment_day';
    public const string UNVERIFIED_VAT_CASH_BASIS = 'vat_cash_basis';
    public const string GAP_NON_DEDUCTIBLE_COSTS = 'non_deductible_costs';
    public const string GAP_ASSETS = 'assets';

    /**
     * @param list<AmountSum>             $sums
     * @param array<string, AmountTotals> $totals             by the value of the kind
     * @param list<AmountLine>            $lines
     * @param list<string>                $unavailableSources
     * @param list<string>                $restrictedSources
     * @param list<string>                $missingRates
     * @param list<string>                $unverified
     * @param list<string>                $knownGaps
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $sums,
        public array $totals,
        public array $lines,
        public array $unavailableSources,
        public array $restrictedSources,
        public array $missingRates,
        public array $unverified,
        public array $knownGaps,
    ) {
    }

    public function totalOf(AmountKind $kind): AmountTotals
    {
        return $this->totals[$kind->value];
    }
}
