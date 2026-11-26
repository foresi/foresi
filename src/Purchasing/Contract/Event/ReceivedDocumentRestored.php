<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A recorded received document came back from the trash as it was, with its evidence number, and counts in the lists and the VAT reports again. Restoring a document that is not in the trash, or a draft, records no event.
 */
#[DomainEvent('purchasing.received_document.restored', version: 1)]
final readonly class ReceivedDocumentRestored implements DomainEventPayload
{
    public function __construct(
        public string $receivedDocumentId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('received_document', $this->receivedDocumentId);
    }
}
