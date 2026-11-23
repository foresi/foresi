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
 * line had to be put on a month's terms. A deal with no priced line has no
 * value: both lists are empty.
 */
final readonly class DealValue
{
    /**
     * @param array<string, Money> $oneOff  by currency code, such as "CZK"
     * @param array<string, Money> $monthly by currency code
     */
    public function __construct(
        public array $oneOff,
        public array $monthly,
        public bool $approximate,
    ) {
    }

    public function hasValue(): bool
    {
        return [] !== $this->oneOff || [] !== $this->monthly;
    }
}
