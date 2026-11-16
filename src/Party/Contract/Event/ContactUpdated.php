<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

/**
 * What the address book knows of a contact changed. Which fields changed is no fact: read the contact. Names, e-mails and notes may identify a person, so they are no facts.
 */
#[DomainEvent('party.contact.updated', version: 1)]
final readonly class ContactUpdated implements DomainEventPayload
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
