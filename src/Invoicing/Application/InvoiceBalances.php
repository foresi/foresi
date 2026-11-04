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
 * PaymentBalances answers from the recorded payments and is authoritative; it is
 * bound by the alias in config/services.yaml. An implementation that cannot tell
 * a paid invoice from an open one answers false from isAuthoritative(), which
 * keeps automatic reminders off. Any implementation must pass
 * InvoiceBalancesContract.
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
