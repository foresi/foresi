<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A payment that came from a bank movement, by bank transfer, and how it is
 * split over invoices of the firm; a refund for an outgoing movement.
 */
final readonly class BankPayment
{
    /**
     * @param \DateTimeImmutable    $receivedOn the day, as a date in the country of the firm
     * @param list<BankPaymentPart> $parts
     */
    public function __construct(
        public string $bankMovementId,
        public \DateTimeImmutable $receivedOn,
        public Money $amount,
        public bool $refund,
        public array $parts,
    ) {
    }
}
