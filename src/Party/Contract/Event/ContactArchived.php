<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A contact was archived: the lists hide it, and it keeps all its data. Names, e-mails and notes may identify a person, so they are no facts.
 */
#[DomainEvent('party.contact.archived', version: 1)]
final readonly class ContactArchived implements DomainEventPayload
{
    public function __construct(
        public string $contactId,
        public ContactKind $kind,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('contact', $this->contactId);
    }
}
