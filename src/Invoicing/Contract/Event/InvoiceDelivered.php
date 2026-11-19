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
 * A document was sent to the customer for the first time: by e-mail from Foresi, or marked
 * sent by hand. The screens say "sent", as that it reached the customer is never certain; the
 * type keeps its first name, "delivered", for the webhook consumers that subscribe to it.
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
