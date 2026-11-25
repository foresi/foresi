<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Infrastructure\Listener;

use Acme\Fleet\Application\Fleet;
use Foresi\Party\Contract\Event\ContactArchived;
use Foresi\Shared\Contract\Event\DomainEventListener;
use Foresi\Shared\Contract\Event\RecordedEvent;

/**
 * An archived contact drives no vehicle any more. An asynchronous listener of
 * Party's event (ADR 0030), idempotent; the relay flushes its unit of work.
 * The class name is the listener's key; keep it.
 */
final readonly class DropArchivedDriver implements DomainEventListener
{
    public const string EVENT = 'party.contact.archived';

    public function __construct(private Fleet $fleet)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof ContactArchived) {
            $this->fleet->withoutDriver($event->payload->contactId);
        }
    }
}
