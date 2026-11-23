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
 * A recorded received document changed: it was saved again (what is "edited"), or its lines, which split the bases of its VAT recap, were set (what is "lines"). `edited` may change anything, the lines included; `lines` is a change of the lines alone. Lines set the same as before change nothing and record nothing. A draft records no event; recording it records purchasing.received_document.recorded. The payload is thin (ADR 0030): read the document itself for its values.
 */
#[DomainEvent('purchasing.received_document.changed', version: 1)]
final readonly class ReceivedDocumentChanged implements DomainEventPayload
{
    public const string EDITED = 'edited';
    public const string LINES = 'lines';

    /**
     * @param string $what edited or lines
     */
    public function __construct(
        public string $receivedDocumentId,
        public string $what,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('received_document', $this->receivedDocumentId);
    }
}
