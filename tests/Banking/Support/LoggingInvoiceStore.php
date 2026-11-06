<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoiceSums;
use App\Invoicing\Application\StoredInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Party\Domain\LegalEntity;

/**
 * The invoice store, writing every lock to a LockLog.
 */
final readonly class LoggingInvoiceStore implements InvoiceStore
{
    public function __construct(private InvoiceStore $store, private LockLog $log)
    {
    }

    public function get(string $id): Invoice
    {
        return $this->store->get($id);
    }

    public function lock(string $id): Invoice
    {
        $this->log->locked($id);

        return $this->store->lock($id);
    }

    public function snapshot(string $id): StoredInvoice
    {
        return $this->store->snapshot($id);
    }

    public function add(Invoice $invoice): void
    {
        $this->store->add($invoice);
    }

    public function lines(Invoice $invoice): array
    {
        return $this->store->lines($invoice);
    }

    public function addLine(InvoiceLine $line): void
    {
        $this->store->addLine($line);
    }

    public function removeLine(InvoiceLine $line): void
    {
        $this->store->removeLine($line);
    }

    public function addAbandoned(AbandonedNumber $number): void
    {
        $this->store->addAbandoned($number);
    }

    public function numberTaken(LegalEntity $firm, string $key, ?Invoice $except): bool
    {
        return $this->store->numberTaken($firm, $key, $except);
    }

    public function page(InvoiceFilter $filter): InvoicePage
    {
        return $this->store->page($filter);
    }

    public function sums(InvoiceFilter $filter): InvoiceSums
    {
        return $this->store->sums($filter);
    }

    public function idsForPeriod(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->store->idsForPeriod($firm, $from, $to);
    }

    public function forAccountant(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $pending): array
    {
        return $this->store->forAccountant($firm, $from, $to, $pending);
    }

    public function lockRow(string $id): void
    {
        $this->log->lockedRow($id);
        $this->store->lockRow($id);
    }

    public function correctionsOf(Invoice $original): array
    {
        return $this->store->correctionsOf($original);
    }

    public function lastFirmOf(string $userId): ?LegalEntity
    {
        return $this->store->lastFirmOf($userId);
    }
}
