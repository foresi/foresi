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
 * Records that a document reached the customer by hand. From then on it cannot be
 * deleted and its series and number are fixed.
 */
final readonly class MarkDelivered
{
    public function __construct(
        private InvoiceStore $store,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Invoice the invoice as saved; anything loaded before is detached
     *
     * @throws \DomainException for an invoice in the trash
     * @throws UnknownInvoice   also for an invoice of another tenant
     */
    public function mark(string $id): Invoice
    {
        return $this->retry->run(fn (): Invoice => $this->work->run(function () use ($id): Invoice {
            $invoice = $this->store->lock($id);
            $invoice->markDelivered(\DateTimeImmutable::createFromInterface($this->clock->now()));

            return $invoice;
        }));
    }
}
