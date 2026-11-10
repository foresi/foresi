<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;

/**
 * The time logged in the active tenant.
 */
interface TimeEntries
{
    public function entry(string $id): ?TimeEntry;

    /**
     * The timer the person runs, if any.
     */
    public function running(Membership $membership): ?TimeEntry;

    /**
     * @return list<TimeEntry>
     */
    public function ofTask(Task $task): array;

    /**
     * The entries, locked for the rest of the transaction, so two invoices
     * never bill one of them; one waiting on the lock reads them as the other
     * left them. Unknown ids are left out. Call it inside a transaction, before
     * anything else of the request loaded them.
     *
     * @param list<string> $ids
     *
     * @return array<string, TimeEntry> by id
     */
    public function lockForInvoicing(array $ids): array;

    /**
     * The ids of the entries the invoice bills, in no particular order.
     *
     * @return list<string>
     */
    public function idsInvoicedBy(string $invoiceId): array;

    public function add(TimeEntry $entry): void;

    /**
     * Removes for good; the audit log keeps what it was.
     */
    public function remove(TimeEntry $entry): void;
}
