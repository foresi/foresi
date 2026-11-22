<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * What a LevyPaymentSource found: the movements that pay the levies, and the
 * keys of the bank sources that could not be read.
 */
final readonly class LevyPayments
{
    /**
     * @param list<LevyPayment> $payments
     * @param list<string>      $unavailable the keys of the bank sources that failed
     */
    public function __construct(
        public array $payments,
        public array $unavailable = [],
    ) {
    }
}
