<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Shared\Domain\Money;

/**
 * The part of a payment that pays, or refunds, one invoice.
 */
final readonly class PaymentAllocationRow
{
    /**
     * @param Money $amount     the part of the payment, positive whichever way the payment went
     * @param Money $settlement the difference written off on the invoice, which was never received
     */
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public Money $amount,
        public Money $settlement,
    ) {
    }
}
