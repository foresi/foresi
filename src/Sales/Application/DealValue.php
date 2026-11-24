<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Shared\Domain\Money;

/**
 * What a deal is worth from its priced lines: once (the one-off lines) and
 * every month (the recurring ones on a month's terms), each as one total per
 * currency, never converted into another. Approximate when a weekly or daily
 * line had to be put on a month's terms. Hourly lines are kept apart per
 * currency, each with its rate and the amount its expected hours make, and are
 * never added into the other two. A deal with no priced line has no value: the
 * lists are empty.
 */
final readonly class DealValue
{
    /**
     * @param array<string, Money>             $oneOff  by currency code, such as "CZK"
     * @param array<string, Money>             $monthly by currency code
     * @param array<string, list<HourlyValue>> $hourly  by currency code, in the order of the lines
     */
    public function __construct(
        public array $oneOff,
        public array $monthly,
        public bool $approximate,
        public array $hourly = [],
    ) {
    }

    public function hasValue(): bool
    {
        return [] !== $this->oneOff || [] !== $this->monthly || [] !== $this->hourly;
    }
}
