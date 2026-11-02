<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;

/**
 * Port: the sends of the active tenant. Call the writing methods inside
 * TransactionalWork, which flushes.
 */
interface InvoiceSends
{
    public function add(InvoiceSend $send): void;

    /**
     * Takes the row of the send for this transaction and gives it as stored now.
     * Lock the invoice first when both are needed, as trash does.
     *
     * @throws UnknownInvoiceSend also for a send of another tenant
     */
    public function lock(string $id): InvoiceSend;

    /**
     * @return list<InvoiceSend> oldest first
     */
    public function ofInvoice(Invoice $invoice): array;

    /**
     * The mark of each invoice, in one query. An invoice without a send is None.
     *
     * @param list<string> $invoiceIds
     *
     * @return array<string, SendMark> by invoice id
     */
    public function marksFor(array $invoiceIds): array;

    /**
     * The send log: newest first.
     */
    public function page(SendLogFilter $filter): SendLogPage;

    /**
     * How many invoices outside the trash have a failed send and no later one that went out.
     */
    public function failing(): int;

    /**
     * How many sends were queued before the moment and no worker has tried yet,
     * which most likely means no worker runs.
     */
    public function untriedBefore(\DateTimeImmutable $before): int;

    /**
     * How many sends still wait after a try the mail server did not take.
     */
    public function retrying(): int;

    /**
     * Whether a send of the invoice still waits to go out.
     */
    public function hasQueued(Invoice $invoice): bool;

    /**
     * Whether the mail server took a send of the invoice, even one whose invoice
     * went to the trash meanwhile and so was never marked delivered.
     */
    public function hasGoneOut(Invoice $invoice): bool;

    /**
     * Whether the invoice has a reminder of this step already, in any status.
     */
    public function hasReminderStep(Invoice $invoice, int $step): bool;

    /**
     * Ends every link of the invoice.
     */
    public function revokeAll(Invoice $invoice, \DateTimeImmutable $at): void;

    /**
     * Stops every send of the invoice that has not gone out.
     */
    public function cancelQueued(Invoice $invoice, \DateTimeImmutable $at): void;

    /**
     * Sends no message will come back to, not queued again since the moment:
     *
     *  - queued and never tried or claimed before the moment: their message was lost
     *    between the commit and the queue;
     *  - claimed before the moment and not tried since: the worker died, or the
     *    message ran out of retries after the server took the mail. A worker that
     *    is still at it comes back when its lease ends, well before.
     *
     * A send whose last try failed and released its claim has its retries in the
     * queue already, so taking it would start a second chain; unless that try is
     * older than the moment the retries are over, so no retry comes any more:
     *
     *  - queued, tried, unclaimed and last tried before that moment: its message
     *    ran out of retries with tries of the send left.
     *
     * @param \DateTimeImmutable $retriesOver a last try before it has no retry to come
     *
     * @return list<string> ids, oldest first
     */
    public function stuck(\DateTimeImmutable $before, \DateTimeImmutable $retriesOver, int $limit): array;

    /**
     * Remembers that the sends were queued again now.
     *
     * @param list<string> $ids
     */
    public function markRequeued(array $ids, \DateTimeImmutable $at): void;
}
