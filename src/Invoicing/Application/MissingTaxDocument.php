<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * A payment of an advance invoice on which VAT arose and that has no tax document
 * yet, with the last day for one. Days left below zero are days past it.
 */
final readonly class MissingTaxDocument
{
    public function __construct(
        public string $advanceId,
        public string $advanceNumber,
        public string $firmName,
        public string $customerName,
        public string $paymentId,
        public \DateTimeImmutable $receivedOn,
        public Money $received,
        public \DateTimeImmutable $deadline,
        public int $daysLeft,
    ) {
    }

    public function isLate(): bool
    {
        return $this->daysLeft < 0;
    }
}
