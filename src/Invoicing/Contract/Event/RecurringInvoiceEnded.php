<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A recurring invoice ended for good: it issues nothing more. The payload is thin (ADR 0030): read the
 * recurring invoice for its customer and schedule.
 */
#[DomainEvent('invoicing.recurring_invoice.ended', version: 1)]
final readonly class RecurringInvoiceEnded implements DomainEventPayload
{
    public function __construct(
        public string $recurringInvoiceId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('recurring_invoice', $this->recurringInvoiceId);
    }
}
