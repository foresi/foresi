<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * A delivered tax document on payment that waits for its corrective tax document,
 * with the last day for one. Days left below zero are days past it.
 */
final readonly class UncorrectedTaxDocument
{
    public function __construct(
        public string $documentId,
        public string $number,
        public string $customerName,
        public Money $amount,
        public bool $refunded,
        public bool $deducted,
        public \DateTimeImmutable $decisiveDate,
        public \DateTimeImmutable $deadline,
        public int $daysLeft,
    ) {
    }

    public function isLate(): bool
    {
        return $this->daysLeft < 0;
    }
}
