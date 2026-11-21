<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The forecast part of the year end (Goal 1, B1, B2), from $from to 31
 * December, in CZK: the sums by month, kind and source, the totals of each
 * kind, and the same split by certainty (document, schedule, plan). A
 * document splits into net and VAT in its own ratio and counts like an actual
 * amount: gross before the VAT-payer day, net from it. A schedule or a plan
 * counts as stated, with no VAT split. The flags say where a split was not
 * possible; the sources that failed or are restricted are listed.
 */
final readonly class ForecastView
{
    public const string FLAG_PLAN_NO_VAT_SPLIT = 'forecast.plan_no_vat_split';
    public const string FLAG_NO_VAT_SPLIT_RESTRICTED = 'forecast.no_vat_split_restricted';
    public const string FLAG_NO_VAT_SPLIT_UNKNOWN = 'forecast.no_vat_split_unknown';

    /**
     * @param list<AmountSum>                            $sums
     * @param array<string, AmountTotals>                $totals             by the value of the kind
     * @param array<string, array<string, AmountTotals>> $byCertainty        by the value of the certainty, then of the kind
     * @param list<string>                               $flags
     * @param list<string>                               $unverified
     * @param list<string>                               $unavailableSources
     * @param list<string>                               $restrictedSources
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $sums,
        public array $totals,
        public array $byCertainty,
        public array $flags,
        public array $unverified,
        public array $unavailableSources,
        public array $restrictedSources,
        public bool $incomplete,
    ) {
    }

    public function totalOf(AmountKind $kind): AmountTotals
    {
        return $this->totals[$kind->value];
    }

    public function totalOfCertainty(ForecastCertainty $certainty, AmountKind $kind): AmountTotals
    {
        return $this->byCertainty[$certainty->value][$kind->value];
    }
}
