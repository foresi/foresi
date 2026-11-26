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
 * Its owner linked an inbox item to a record, which became an output of the
 * item and a link of its e-mail activity. Thin on purpose (ADR 0030): the id of
 * the item and the kind of the record, such as deal; read the item for the
 * record. Only the owner of the item sees the event.
 */
#[DomainEvent('inbox.item.linked', version: 1)]
final readonly class InboxItemLinked implements DomainEventPayload
{
    public function __construct(public string $itemId, public string $kind)
    {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(InboxItemReceived::SUBJECT, $this->itemId);
    }
}
