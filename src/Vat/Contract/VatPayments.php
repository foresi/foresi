<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

/**
 * What a VatPaymentSource found: the movements to and from the tax office,
 * oldest first, and the keys of the bank sources that could not be read.
 */
final readonly class VatPayments
{
    /**
     * @param list<VatPayment> $payments
     * @param list<string>     $unavailable
     */
    public function __construct(
        public array $payments,
        public array $unavailable = [],
    ) {
    }
}
