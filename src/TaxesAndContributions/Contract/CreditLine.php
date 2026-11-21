<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A tax credit of the rule set: whether the profile claims it, the amount it
 * gives, and a short English text saying who may claim it, the condition and
 * the amount, which the screen translates by its key.
 */
final readonly class CreditLine
{
    public function __construct(
        public string $key,
        public bool $claimed,
        public Money $amount,
        public string $explanation,
    ) {
    }
}
