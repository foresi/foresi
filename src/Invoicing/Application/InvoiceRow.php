<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * One line of the invoice list, with the names as the document holds them. Nothing is
 * paid yet in this step, so whatever is past its due date is overdue. The deletion is
 * given in the time zone of the country that issued the document.
 */
final readonly class InvoiceRow
{
    public function __construct(
        public string $id,
        public string $number,
        public string $firmName,
        public string $customerName,
        public \DateTimeImmutable $issueDate,
        public \DateTimeImmutable $dueDate,
        public Money $payable,
        public bool $delivered,
        public bool $overdue,
        public bool $trashed,
        public ?\DateTimeImmutable $deletedAt = null,
        public ?string $deletedBy = null,
        public ?string $deletedReason = null,
    ) {
    }
}
