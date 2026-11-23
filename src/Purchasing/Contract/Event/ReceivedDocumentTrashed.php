<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A recorded received document was moved to the trash: it keeps its evidence number, leaves the lists and the VAT reports, and takes no change or payment until it is restored. A draft in the trash records no event. The payload is thin (ADR 0030): the reason stays in the document's history.
 */
#[DomainEvent('purchasing.received_document.trashed', version: 1)]
final readonly class ReceivedDocumentTrashed implements DomainEventPayload
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
