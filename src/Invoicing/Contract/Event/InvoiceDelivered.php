<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

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
