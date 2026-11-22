<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A ready document went out for signature. No names or e-mails (ADR 0030).
 */
#[DomainEvent('documents.document.sent_for_signature', version: 1)]
final readonly class DocumentSentForSignature implements DomainEventPayload
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
