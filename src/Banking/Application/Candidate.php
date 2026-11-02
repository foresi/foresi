<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Shared\Domain\Money;

/**
 * An open invoice a movement may pay: what is still to pay on it, and what a
 * person needs to tell it from the others.
 */
final readonly class Candidate
{
    /**
     * @param Money              $remaining what is still to pay, in the currency of the invoice; above zero
     * @param \DateTimeImmutable $issueDate an invoice issued after the money came is never paired by itself
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
