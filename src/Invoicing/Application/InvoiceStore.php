<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
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
     * the session over, so whatever was loaded before is detached: work from what it
     * returns. Call it inside TransactionalWork.
     *
     * @throws UnknownInvoice  also for an invoice of another tenant
     * @throws \LogicException when the session holds unsaved changes, which it would lose
     */
    public function lock(string $id): Invoice;

    /**
     * Locks the row of an invoice until the transaction ends, inside a transaction
     * that has written already: unlike lock(), it keeps what the session holds.
     *
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function lockRow(string $id): void;

    /**
     * The invoice and its lines in one statement, so a save that commits meanwhile
     * shows entirely or not at all. It starts the session over, as lock() does.
     *
     * @throws UnknownInvoice  also for an invoice of another tenant
     * @throws \LogicException when the session holds unsaved changes, which it would lose
     */
    public function snapshot(string $id): StoredInvoice;

    public function add(Invoice $invoice): void;

    /**
     * @return list<InvoiceLine> in the order of the document
     */
    public function lines(Invoice $invoice): array;

    public function addLine(InvoiceLine $line): void;

    /**
     * The corrective tax documents of an invoice that are not in the trash.
     *
     * @return list<Invoice>
     */
    public function correctionsOf(Invoice $original): array;

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
     * In the order of the filter; a page out of range gives the nearest one. A firm
     * without an invoice filters nothing.
     */
    public function page(InvoiceFilter $filter): InvoicePage;

    /**
     * What the documents page() lists under the same filter add up to, over all pages.
     */
    public function sums(InvoiceFilter $filter): InvoiceSums;

    /**
     * The invoices of a firm whose tax point falls in a period, both days included,
     * in the order of their numbers. The trash stays out. The tax point, because it
     * decides the VAT period.
     *
     * @return list<string> ids
     */
    public function idsForPeriod(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * The documents of a firm for the accountant in a period, both days included,
     * in the order of their numbers: a tax document by its tax point, an advance
     * invoice, which has none, by its issue date. The trash stays out.
     *
     * @param bool $pending only those never exported or edited after their export
     *
     * @return array<string, DocumentKind> kinds by id
     */
    public function forAccountant(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $pending): array;

    /**
     * The active firm the user issued in last.
     */
    public function lastFirmOf(string $userId): ?LegalEntity;
}
