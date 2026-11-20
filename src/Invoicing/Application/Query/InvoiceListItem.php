<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Query;

use Foresi\Invoicing\Application\InvoiceRow;

/**
 * One document of a slice: the row of the list with the ids and dates the API adds to it.
 * The currency is the one of its money.
 */
final readonly class InvoiceListItem
{
    public function __construct(
        public InvoiceRow $row,
        public string $customerId,
        public ?\DateTimeImmutable $taxPointDate,
        public \DateTimeImmutable $createdAt,
        /** When it was first sent to the customer, by e-mail or marked by hand. */
        public ?\DateTimeImmutable $sentAt = null,
    ) {
    }
}
