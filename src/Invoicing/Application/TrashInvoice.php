<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\Invoice;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Moves an invoice to the trash and back. It keeps its number and content either
 * way, and the audit log explains the gap.
 */
final readonly class TrashInvoice
{
    public function __construct(
        private InvoiceStore $store,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Only the id of the invoice given here counts. A refusal detaches what was loaded.
     *
     * @throws \DomainException once delivered, without a reason, or when it is in the trash already
     */
    public function trash(Invoice $invoice, string $reason): void
    {
        $id = $invoice->id();
        $this->retry->run(fn () => $this->work->run(function () use ($id, $reason): void {
            $this->store->lock($id)->trash(\DateTimeImmutable::createFromInterface($this->clock->now()), $this->actor->userId(), $reason);
        }));
    }

    public function restore(Invoice $invoice): void
    {
        $id = $invoice->id();
        $this->retry->run(fn () => $this->work->run(function () use ($id): void {
            $this->store->lock($id)->restore();
        }));
    }
}
