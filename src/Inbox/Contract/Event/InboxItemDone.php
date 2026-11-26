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
 * Its owner marked an inbox item done: dealt with. Thin on purpose (ADR 0030):
 * the id of the item only; only the owner of the item sees the event.
 */
#[DomainEvent('inbox.item.done', version: 1)]
final readonly class InboxItemDone implements DomainEventPayload
{
    public function __construct(public string $itemId)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(InboxItemReceived::SUBJECT, $this->itemId);
    }
}
