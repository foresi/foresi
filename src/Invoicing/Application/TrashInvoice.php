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
    public function __construct(private TransactionalWork $work, private AuditActor $actor, private ClockInterface $clock)
    {
    }

    /**
     * @throws \DomainException once delivered, without a reason, or when it is in the trash already
     */
    public function trash(Invoice $invoice, string $reason): void
    {
        // Outside the unit of work: a refusal must not detach what the caller holds.
        $invoice->trash(\DateTimeImmutable::createFromInterface($this->clock->now()), $this->actor->userId(), $reason);
        $this->work->run(static fn (): null => null);
    }

    public function restore(Invoice $invoice): void
    {
        $invoice->restore();
        $this->work->run(static fn (): null => null);
    }
}
