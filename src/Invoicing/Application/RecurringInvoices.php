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

    public function add(RecurringInvoice $template): void;

    /**
     * Only a template that never issued an invoice; its lines go with it.
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
     * How many templates that have not ended failed on their last run.
     */
    public function failing(): int;
}
