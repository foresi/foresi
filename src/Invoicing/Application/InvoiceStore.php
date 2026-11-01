<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Party\Domain\LegalEntity;

/**
 * The invoices of the active tenant. Call the writing methods inside
 * TransactionalWork, which flushes.
 */
interface InvoiceStore
{
    public const int PER_PAGE = 25;

    /**
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function get(string $id): Invoice;

    /**
     * Takes the row of the invoice for this transaction and gives it as stored now.
     * Every change of a stored invoice starts with this, before anything else is
     * read, so it sees what the save before it left and the next one waits. It starts
     * the session over, so whatever was loaded before is detached and nothing unsaved
     * may be pending. Call it inside TransactionalWork.
     *
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function lock(string $id): Invoice;

    public function add(Invoice $invoice): void;

    /**
     * @return list<InvoiceLine> in the order of the document
     */
    public function lines(Invoice $invoice): array;

    public function addLine(InvoiceLine $line): void;

    public function removeLine(InvoiceLine $line): void;

    public function addAbandoned(AbandonedNumber $number): void;

    /**
     * Whether a firm has used the number: on an invoice, in the trash too, or as a
     * number an edit gave up, which even that invoice cannot take back.
     *
     * @param string       $key    the NumberKey of the number
     * @param Invoice|null $except an invoice whose current number does not count
     */
    public function numberTaken(LegalEntity $firm, string $key, ?Invoice $except): bool;

    /**
     * Newest first; a page out of range gives the nearest one.
     */
    public function page(InvoiceFilter $filter): InvoicePage;

    /**
     * The active firm the user issued in last.
     */
    public function lastFirmOf(string $userId): ?LegalEntity;
}
