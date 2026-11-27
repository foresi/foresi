<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A recorded received document became paid in full: the payment named, on the day it names (YYYY-MM-DD), left nothing to pay. A partial payment records no event.
 */
#[DomainEvent('received-documents.received_document.paid', version: 1)]
final readonly class ReceivedDocumentPaid implements DomainEventPayload
{
    public function __construct(
        public string $receivedDocumentId,
        public string $firmId,
        public string $paymentId,
        public string $paidOn,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('received_document', $this->receivedDocumentId);
    }
}
