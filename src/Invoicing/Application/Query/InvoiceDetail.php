<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\InvoicePaymentLine;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Shared\Domain\Money;

/**
 * One invoice with what belongs to it. Paid is what its payments allocated, corrections what
 * its corrective documents add to what it asks for, remaining what is still to pay after both
 * (below zero when something is to refund), and toRefund what may go back to the customer.
 */
final readonly class InvoiceDetail
{
    /**
     * @param list<InvoiceLine>         $lines      in the order of the document
     * @param list<InvoicePaymentLine>  $payments   not cancelled, oldest first
     * @param list<Invoice>             $corrective the corrective documents not in the trash
     * @param list<InvoiceHistoryEntry> $history    oldest first
     */
    public function __construct(
        public Invoice $invoice,
        public array $lines,
        public array $payments,
        public array $corrective,
        public Money $paid,
        public Money $corrections,
        public Money $remaining,
        public Money $toRefund,
        public array $history,
    ) {
    }
}
