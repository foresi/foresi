<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Application\InvoiceRow;

/**
 * One document of a slice: the row of the list with the ids and dates the API adds to it.
 * The currency is the one of its money.
 */
final readonly class InvoiceListItem
{
    public function __construct(
        public InvoiceRow $row,
        public string $firmId,
        public string $customerId,
        public ?\DateTimeImmutable $taxPointDate,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
