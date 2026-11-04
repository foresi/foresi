<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\InvalidDeletionReason;
use App\Invoicing\Domain\Invoice;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Moves an invoice to the trash and back. It keeps its number and content either
 * way, and the audit log explains the gap. Trash stops the sends still queued and
 * ends every link of the invoice, in the same transaction; restoring brings no
 * link back, and sending again makes a new one. A document with payments is not a
 * mistake to bin: they are removed first.
 */
final readonly class TrashInvoice
{
    public function __construct(
        private InvoiceStore $store,
        private InvoiceSends $sends,
        private InvoicePayments $payments,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Invoice the invoice as saved; anything loaded before is detached
     *
     * @throws InvalidDeletionReason without a reason, or with one past Invoice::MAX_REASON_LENGTH
     * @throws \DomainException      once delivered, or when it is in the trash already
     * @throws PaymentRefused        with HAS_PAYMENTS while a payment not cancelled pays it
     * @throws UnknownInvoice        also for an invoice of another tenant
     */
    public function trash(string $id, string $reason): Invoice
    {
        return $this->retry->run(fn (): Invoice => $this->work->run(function () use ($id, $reason): Invoice {
            $invoice = $this->store->lock($id);
            if ($this->payments->hasPayments($invoice)) {
                throw new PaymentRefused(PaymentRefused::HAS_PAYMENTS);
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $invoice->trash($now, $this->actor->userId(), $reason);
            $this->sends->cancelQueued($invoice, $now);
            $this->sends->revokeAll($invoice, $now);

            return $invoice;
        }));
    }

    /**
     * @return Invoice the invoice as saved; anything loaded before is detached
     *
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function restore(string $id): Invoice
    {
        return $this->retry->run(fn (): Invoice => $this->work->run(function () use ($id): Invoice {
            $invoice = $this->store->lock($id);
            $invoice->restore();

            return $invoice;
        }));
    }
}
