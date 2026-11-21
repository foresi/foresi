<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The receivables and payables open at the end of a day, in CZK at the ČNB
 * rate of that day: the income kind are the receivables, the cost kind the
 * payables. Each is counted without its VAT when the owner is a VAT payer on
 * the day, and with it before. A source that is not installed or failed is
 * listed in $unavailableSources, and a currency without a rate on the day in
 * $missingRates; their amounts are left out.
 */
final readonly class OpenAmountsView
{
    /**
     * @param array<string, AmountTotals> $totals             by the value of the kind
     * @param list<string>                $unavailableSources
     * @param list<string>                $missingRates
     * @param list<string>                $unverified
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public array $totals,
        public array $unavailableSources,
        public array $missingRates,
        public array $unverified,
    ) {
    }

    public function totalOf(AmountKind $kind): AmountTotals
    {
        return $this->totals[$kind->value] ?? throw new \OutOfBoundsException(\sprintf('No total of %s.', $kind->value));
    }
}
