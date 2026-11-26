<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * The numbers of invoices by their ids, for a module that names the invoice
 * its work was billed on.
 */
interface InvoiceNumbers
{
    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, string> numbers by invoice id; an invoice not found is left out
     */
    public function numbersOf(array $invoiceIds): array;
}
