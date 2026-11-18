<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A document reached the customer for the first time.
 */
#[DomainEvent('invoicing.invoice.delivered', version: 1)]
final readonly class InvoiceDelivered implements DomainEventPayload
{
    public function __construct(
        public string $invoiceId,
        public DeliveryChannel $channel,
        public string $deliveredAt,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
