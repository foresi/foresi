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
 * What the address book knows of a contact changed. Which of its own fields changed is no fact: read the contact. Names, e-mails and notes may identify a person, so they are no facts.
 * The keys of the custom fields that changed (ADR 0045) are, never their values; empty when none did.
 */
#[DomainEvent('party.contact.updated', version: 1)]
final readonly class ContactUpdated implements DomainEventPayload
{
    /**
     * @param list<string> $customFields the keys of the custom fields that changed, such as "acme_fleet_industry"
     */
    public function __construct(
        public string $contactId,
        public ContactKind $kind,
        public array $customFields = [],
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('contact', $this->contactId);
    }
}
