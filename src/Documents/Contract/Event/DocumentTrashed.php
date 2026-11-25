<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A cancelled document went to the trash; it is kept there with its history. No names or e-mails (ADR 0030).
 */
#[DomainEvent('documents.document.trashed', version: 1)]
final readonly class DocumentTrashed implements DomainEventPayload
{
    /**
     * @param string $typeCode the code of the document's type, such as "nda"
     * @param string $status   the document's status after the change
     */
    public function __construct(
        public string $documentId,
        public string $typeCode,
        public string $status,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('document', $this->documentId);
    }
}
