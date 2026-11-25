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
 * A received document was recorded for the first time, from a draft or typed in at once, on a screen or over the API. The total is what the document asks to be paid, in its currency, as a decimal (negative for a credit note); the due date is YYYY-MM-DD or null. The supplier is a contact id, never its name.
 */
#[DomainEvent('purchasing.received_document.recorded', version: 1)]
final readonly class ReceivedDocumentRecorded implements DomainEventPayload
{
    public function __construct(
        public string $receivedDocumentId,
        public string $firmId,
        public string $kind,
        public string $supplierId,
        public string $total,
        public string $currency,
        public ?string $dueDate,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('received_document', $this->receivedDocumentId);
    }
}
