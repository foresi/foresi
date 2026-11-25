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
 * Its owner discarded an inbox item; its mail is removed 30 days later unless
 * something came of it. Thin on purpose (ADR 0030): the id of the item only;
 * only the owner of the item sees the event.
 */
#[DomainEvent('inbox.item.discarded', version: 1)]
final readonly class InboxItemDiscarded implements DomainEventPayload
{
    public function __construct(public string $itemId)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(InboxItemReceived::SUBJECT, $this->itemId);
    }
}
