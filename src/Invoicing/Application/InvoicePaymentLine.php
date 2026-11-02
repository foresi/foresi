<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Money;

/**
 * One payment as one invoice sees it: its part of the payment, and the difference
 * written off on it.
 */
final readonly class InvoicePaymentLine
{
    /**
     * @param string|null $createdBy the user id; null when no person recorded it
     */
    public function __construct(
        public string $paymentId,
        public \DateTimeImmutable $receivedOn,
        public Money $allocated,
        public Money $settlement,
        public PaymentMethod $method,
        public PaymentSource $source,
        public ?string $bankMovementId,
        public ?string $createdBy,
    ) {
    }
}
