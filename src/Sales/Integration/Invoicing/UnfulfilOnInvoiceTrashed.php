<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Invoicing;

use Foresi\Invoicing\Contract\Event\InvoiceTrashed;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Sales\Contract\BillingDocumentType;
use Foresi\Shared\Contract\Event\DomainEventListener;
use Foresi\Shared\Contract\Event\RecordedEvent;

/**
 * An invoice in the trash fulfils no planned invoice of a won deal: the entries it fulfilled count in the forecast again. An asynchronous listener of Invoicing's event (ADR 0030), idempotent;
 * the relay flushes its unit of work. The class name is the listener's key; keep it.
 */
final readonly class UnfulfilOnInvoiceTrashed implements DomainEventListener
{
    public const string EVENT = 'invoicing.invoice.trashed';

    public function __construct(private BillingPlanWrites $plans)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof InvoiceTrashed) {
            $this->plans->documentGone(BillingDocumentType::Invoice, $event->payload->invoiceId);
        }
    }
}
