<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

/**
 * The SQL side of InvoiceBalances: a condition that queries over many invoices
 * put in their WHERE clause, so invoices that owe nothing never take a place in
 * a limited result, as settled invoices would ahead of a due one in the reminder
 * queue. Whatever binds InvoiceBalances binds this too, from the same knowledge:
 * DueDateBalances leaves out the trash, the payments module also what was paid.
 *
 * The condition may let through an invoice that owes nothing, which the caller
 * still drops by outstanding(); it must never leave out one that owes something.
 */
interface OwingInvoiceCondition
{
    /**
     * @param string $invoice the alias of the invoice table in the query
     *
     * @return string an SQL condition without parameters, true for each invoice that may owe something
     */
    public function owing(string $invoice): string;
}
