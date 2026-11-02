<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * Port: what is still owed on invoices of the active tenant. Reminders ask it
 * before they are queued and again before they are delivered.
 *
 * Until payments exist, DueDateBalances answers from the invoice alone and is not
 * authoritative. The payments module replaces the binding of this interface (the
 * alias in config/services.yaml) with one that knows what was paid, and answers
 * true from isAuthoritative(). Any implementation must pass
 * InvoiceBalancesContractTest.
 */
interface InvoiceBalances
{
    /**
     * Every given id is answered, in one query. An invoice that is settled, paid or
     * in the trash owes zero in its own currency.
     *
     * @param list<string> $invoiceIds
     *
     * @return array<string, Money> by invoice id
     *
     * @throws UnknownInvoice if any id is not an invoice of this tenant
     */
    public function outstanding(array $invoiceIds): array;

    /**
     * Whether the amounts include payments. When false, they only tell what was
     * invoiced, and nothing may act on them without the user's say, such as sending
     * a reminder by itself.
     */
    public function isAuthoritative(): bool;
}
