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
 * A contact was added to the address book, by a person, an import or a client of the API. Names, e-mails and notes may identify a person, so they are no facts.
 */
#[DomainEvent('party.contact.created', version: 1)]
final readonly class ContactCreated implements DomainEventPayload
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
