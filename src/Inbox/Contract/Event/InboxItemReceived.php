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
 * A mail arrived at a person's inbox address and became an inbox item. Thin on
 * purpose (ADR 0030): the id of the item only, no sender, subject or text; only
 * the owner of the item sees the event.
 */
#[DomainEvent('inbox.item.received', version: 1)]
final readonly class InboxItemReceived implements DomainEventPayload
{
    public const string SUBJECT = 'inbox_item';

    public function __construct(public string $itemId)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(self::SUBJECT, $this->itemId);
    }
}
