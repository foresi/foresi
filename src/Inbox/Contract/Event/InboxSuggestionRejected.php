<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * The owner of an inbox item rejected a suggestion, or it was rejected when
 * they discarded the item. Thin on purpose (ADR 0030): the ids of
 * the suggestion and its item only, nothing of what it proposes; only the
 * owner of the item sees the event.
 */
#[DomainEvent('inbox.suggestion.rejected', version: 1)]
final readonly class InboxSuggestionRejected implements DomainEventPayload
{
    public function __construct(public string $suggestionId, public string $itemId)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(InboxItemReceived::SUBJECT, $this->itemId);
    }
}
