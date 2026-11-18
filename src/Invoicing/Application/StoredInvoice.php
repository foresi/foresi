<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;

/**
 * An invoice and its lines as one read of the database saw them.
 */
final readonly class StoredInvoice
{
    /**
     * @param list<InvoiceLine> $lines in the order of the document
     */
    public function __construct(public Invoice $invoice, public array $lines)
    {
    }
}
