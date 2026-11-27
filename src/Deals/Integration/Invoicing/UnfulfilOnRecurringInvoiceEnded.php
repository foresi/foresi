<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Invoicing;

use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Deals\Contract\BillingDocumentType;
use Foresi\Invoicing\Contract\Event\RecurringInvoiceEnded;
use Foresi\Shared\Contract\Event\DomainEventListener;
use Foresi\Shared\Contract\Event\RecordedEvent;

/**
 * A recurring invoice that ended fulfils no planned invoice of a won deal any more: the entries it fulfilled count in the forecast again. An asynchronous listener of Invoicing's event (ADR 0030), idempotent;
 * the relay flushes its unit of work. The class name is the listener's key; keep it.
 */
final readonly class UnfulfilOnRecurringInvoiceEnded implements DomainEventListener
{
    public const string EVENT = 'invoicing.recurring_invoice.ended';

    public function __construct(private BillingPlanWrites $plans)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof RecurringInvoiceEnded) {
            $this->plans->documentGone(BillingDocumentType::RecurringInvoice, $event->payload->recurringInvoiceId);
        }
    }
}
