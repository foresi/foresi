<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * What the accountant has: the documents of a pack marked exported together, and
 * the mark of one document cleared, so the next pack takes it again.
 */
final readonly class DocumentExports
{
    public function __construct(
        private InvoiceStore $store,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * All of them or none, at the same moment. The rows are locked in the order of
     * their ids, so two exports of the same documents never wait on each other.
     *
     * @param list<string> $ids of documents of any kind, in the trash too
     *
     * @throws UnknownInvoice also for a document of another tenant; nothing is marked
     */
    public function markExported(array $ids): void
    {
        $ids = array_unique($ids);
        sort($ids);
        $this->retry->run(fn () => $this->work->run(function () use ($ids): void {
            foreach ($ids as $id) {
                $this->store->lockRow($id);
            }
            $at = \DateTimeImmutable::createFromInterface($this->clock->now());
            // Locking the first again starts the session over, so each loads as stored now.
            foreach ($ids as $index => $id) {
                (0 === $index ? $this->store->lock($id) : $this->store->get($id))->markExported($at);
            }
        }));
    }

    /**
     * @return Invoice the document as saved; anything loaded before is detached
     *
     * @throws UnknownInvoice also for a document of another tenant
     */
    public function clear(string $id): Invoice
    {
        return $this->retry->run(fn (): Invoice => $this->work->run(function () use ($id): Invoice {
            $invoice = $this->store->lock($id);
            $invoice->clearExport();

            return $invoice;
        }));
    }
}
