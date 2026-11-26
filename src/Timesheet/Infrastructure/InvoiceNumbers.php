<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the numbers of the invoices that billed time, which a line of time
 * names instead of its edit and delete actions, and where it links them. The
 * time screens ask it and know nothing of invoices; the billing of time
 * (Integration/Invoicing/InvoicingInvoiceNumbers) answers it.
 */
#[AutoconfigureTag(self::TAG)]
interface InvoiceNumbers
{
    public const string TAG = 'foresi.timesheet.invoice_numbers';

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, string> numbers by invoice id; an invoice not found is left out
     */
    public function numbersOf(array $invoiceIds): array;

    /**
     * Where a line of time links the invoice that billed it, or null when this provider does not know it.
     */
    public function url(string $invoiceId): ?string;
}
