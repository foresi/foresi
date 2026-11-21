<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * What a change of the expense method between two years adds to the year
 * before (old T10): the receivables open at its last day raise its tax base,
 * and with actual costs before, the open payables lower it. The tax on the
 * change, at the marginal rate of the year before, is due by the return
 * deadline of the year of the change, with an additional return for the
 * year before. It is shown as a warning with its amount and a payment of its
 * own, never inside the totals of the year. A tax below zero is what the
 * additional return would lower; no payment comes of it.
 */
final readonly class SwitchAdjustment
{
    public const string INTO_FLAT_RATE = 'flat_rate';

    /**
     * @param string     $into            the value of the method switched to, or flat_rate
     * @param Money|null $previousTaxBase the tax base of the year before, null when it cannot be computed
     */
    public function __construct(
        public int $adjustedYear,
        public ExpenseMethod $from,
        public string $into,
        public \DateTimeImmutable $openOn,
        public Money $receivables,
        public Money $payables,
        public Money $baseChange,
        public ?Money $previousTaxBase,
        public string $rate,
        public Money $tax,
        public \DateTimeImmutable $dueOn,
        public string $sourceUrl,
    ) {
    }
}
