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
 * A document went to the trash before it was delivered. The reason is free text
 * that may name a person, so it is no fact.
 */
#[DomainEvent('invoicing.invoice.trashed', version: 1)]
final readonly class InvoiceTrashed implements DomainEventPayload
{
    public function __construct(
        public string $invoiceId,
        public DocumentKindValue $kind,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
