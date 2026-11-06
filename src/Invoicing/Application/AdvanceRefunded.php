<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * Money went back on an advance invoice. RecordPayment dispatches it inside the
 * transaction of the refund, under the lock of the advance, and issues no tax
 * document for it: the tax document of the payment is corrected instead, by a
 * corrective tax document on payment, which a listener of this event issues.
 */
final readonly class AdvanceRefunded
{
    /**
     * @param Money $refunded the part of the refund allocated to the advance, positive
     */
    public function __construct(
        public string $paymentId,
        public string $advanceId,
        public Money $refunded,
    ) {
    }
}
