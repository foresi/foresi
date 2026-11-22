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
 * A signer of a document was marked signed. No names or e-mails (ADR 0030).
 */
#[DomainEvent('documents.signer.signed', version: 1)]
final readonly class SignerSigned implements DomainEventPayload
{
    /**
     * @param string $method "manual", or the code of a signature provider
     */
    public function __construct(
        public string $documentId,
        public string $signerId,
        public string $typeCode,
        public string $method,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('document', $this->documentId);
    }
}
