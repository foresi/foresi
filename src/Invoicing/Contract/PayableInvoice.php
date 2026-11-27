<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Money;

/**
 * An invoice a bank payment may pay, or a refund give money back on: what is
 * still to pay or to refund on it, and what a person needs to tell it from the
 * others.
 */
final readonly class PayableInvoice
{
    /**
     * @param Money              $remaining what is still to pay, or to refund, in the currency of the invoice; above zero
     * @param \DateTimeImmutable $issueDate for a refund, the later of its own and its corrective documents'
     */
    public function __construct(
        public string $invoiceId,
        public string $number,
        public \DateTimeImmutable $dueDate,
        public Money $remaining,
        public string $customerName,
        public \DateTimeImmutable $issueDate,
    ) {
    }
}
