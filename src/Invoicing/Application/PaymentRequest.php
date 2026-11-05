<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Money;

/**
 * A payment to record, and how it is split over invoices of the firm.
 */
final readonly class PaymentRequest
{
    /**
     * @param \DateTimeImmutable      $receivedOn     the day, as a date in the country of the firm
     * @param string|null             $bankMovementId the movement a bank payment came from; null for any other
     * @param list<AllocationRequest> $allocations    positive parts for a refund too: the direction carries the sign
     */
    public function __construct(
        public string $firmId,
        public \DateTimeImmutable $receivedOn,
        public Money $amount,
        public PaymentMethod $method,
        public PaymentSource $source,
        public ?string $bankMovementId,
        public string $note,
        public array $allocations,
        public PaymentDirection $direction = PaymentDirection::Incoming,
    ) {
    }
}
