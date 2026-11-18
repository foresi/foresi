<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the numbers of the invoices that billed time, which a line of time
 * names instead of its edit and delete actions. Work declares it and knows
 * nothing of invoices; the module that bills time answers it.
 */
#[AutoconfigureTag(self::TAG)]
interface InvoiceNumbers
{
    public const string TAG = 'app.invoice_numbers';

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, string> numbers by invoice id; an invoice not found is left out
     */
    public function numbersOf(array $invoiceIds): array;
}
