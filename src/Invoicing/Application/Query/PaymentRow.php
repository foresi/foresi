<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Money;

/**
 * One payment with what it pays. The amount is positive either way: the
 * direction tells whether the money came in or went back.
 */
final readonly class PaymentRow
{
    /**
     * @param string|null                $bankMovementId the movement a bank payment came from
     * @param \DateTimeImmutable|null    $cancelledAt    set once the payment is cancelled, and then it pays nothing
     * @param list<PaymentAllocationRow> $allocations    by invoice number
     */
    public function __construct(
        public string $id,
        public string $firmId,
        public string $firmName,
        public \DateTimeImmutable $receivedOn,
        public Money $amount,
        public PaymentDirection $direction,
        public PaymentMethod $method,
        public PaymentSource $source,
        public string $note,
        public ?string $bankMovementId,
        public ?\DateTimeImmutable $cancelledAt,
        public \DateTimeImmutable $createdAt,
        public array $allocations,
    ) {
    }
}
