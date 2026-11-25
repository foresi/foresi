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
 * Its owner took a done or discarded inbox item back to new. Thin on purpose
 * (ADR 0030): the id of the item only; only the owner of the item sees the event.
 */
#[DomainEvent('inbox.item.reopened', version: 1)]
final readonly class InboxItemReopened implements DomainEventPayload
{
    public function __construct(public string $itemId)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(InboxItemReceived::SUBJECT, $this->itemId);
    }
}
