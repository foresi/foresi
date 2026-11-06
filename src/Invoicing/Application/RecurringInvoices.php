<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;

/**
 * The recurring templates of the active tenant. Call the writing methods inside
 * TransactionalWork, which flushes.
 */
interface RecurringInvoices
{
    public const int PER_PAGE = 25;

    /**
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function get(string $id): RecurringInvoice;

    /**
     * Takes the row of the template for this transaction and gives it as stored now,
     * as InvoiceStore::lock() does for an invoice: every change of a template starts
     * here, so a run issuing it and a person editing it wait for each other. It starts
     * the session over; work from what it returns. Call it inside TransactionalWork.
     *
     * @throws UnknownRecurringInvoice also for a template of another tenant
     * @throws \LogicException         when the session holds unsaved changes, which it would lose
     */
    public function lock(string $id): RecurringInvoice;

    /**
     * The template as stored now, for reading it and its lines outside a transaction:
     * it starts the session over, as lock() does, so nothing remembered from earlier
     * answers for it.
     *
     * @throws UnknownRecurringInvoice also for a template of another tenant
     * @throws \LogicException         when the session holds unsaved changes, which it would lose
     */
    public function current(string $id): RecurringInvoice;

    /**
     * Takes the row as lock() does, for a transaction that has written already, as an
     * issue has: the session stays and only the template is read again from its row.
     * Call it inside TransactionalWork.
     *
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function lockDuringWrite(string $id): RecurringInvoice;

    /**
     * Whether an invoice was issued for this occurrence of the template, one in the
     * trash too: the trash keeps the occurrence taken.
     */
    public function occurrenceIssued(string $id, \DateTimeImmutable $occurrence): bool;

    public function add(RecurringInvoice $template): void;

    /**
     * Only a template that never issued an invoice, or an untouched import, which
     * issued only in the system it came from; its lines go with it.
     */
    public function remove(RecurringInvoice $template): void;

    /**
     * @return list<RecurringInvoiceLine> in the order of the template
     */
    public function lines(RecurringInvoice $template): array;

    public function addLine(RecurringInvoiceLine $line): void;

    public function removeLine(RecurringInvoiceLine $line): void;

    /**
     * The active templates whose next date is on or before the calendar day of $today.
     *
     * @return list<string> ids, in id order
     */
    public function due(\DateTimeImmutable $today): array;

    /**
     * In the order of RecurringPage; a page out of range gives the nearest one.
     */
    public function page(RecurringFilter $filter): RecurringPage;

    /**
     * How many templates that have not ended failed on their last run. A refused
     * send counts only while an invoice of the template still waits to be sent:
     * sending it by hand or marking it delivered ends the failure at once.
     */
    public function failing(): int;

    /**
     * How many invoices issued from a template were never sent and are not in the
     * trash: those held for review, and those whose send was refused.
     *
     * @param string|null $firmId only the invoices of this firm, as the list filtered by it shows them
     */
    public function inReview(?string $firmId = null): int;
}
