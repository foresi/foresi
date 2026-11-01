<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * One line of the invoice list, with the names as the document holds them.
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
        public bool $trashed,
    ) {
    }
}
