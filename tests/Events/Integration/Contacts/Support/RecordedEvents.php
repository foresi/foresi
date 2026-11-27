<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Integration\Contacts\Support;

use Doctrine\DBAL\Connection;
use Foresi\Contacts\Contract\Event\ContactArchived;
use Foresi\Contacts\Contract\Event\ContactCreated;
use Foresi\Contacts\Contract\Event\ContactKind;
use Foresi\Contacts\Contract\Event\ContactRestored;
use Foresi\Contacts\Contract\Event\ContactUpdated;
use Foresi\Events\Infrastructure\Relay\EventRelay;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * Real contract events recorded as a use case records them, and numbered by
 * the relay, for the tests of the feed. They are the events of the address
 * book, which has several types with a subject each; the core alone has one
 * event of the firm, so the tests of the feed need the contacts module.
 */
final readonly class RecordedEvents
{
    public function __construct(
        private Connection $connection,
        private EventRecorder $recorder,
        private EventRelay $relay,
    ) {
    }

    public function record(DomainEventPayload $event): void
    {
        $this->connection->transactional(function () use ($event): void {
            $this->recorder->record($event);
        });
    }

    public function number(): void
    {
        $this->relay->relayAll();
    }

    public static function created(string $contactId): ContactCreated
    {
        return new ContactCreated($contactId, ContactKind::Company);
    }

    public static function updated(string $contactId): ContactUpdated
    {
        return new ContactUpdated($contactId, ContactKind::Company);
    }

    public static function archived(string $contactId): ContactArchived
    {
        return new ContactArchived($contactId, ContactKind::Company);
    }

    public static function restored(string $contactId): ContactRestored
    {
        return new ContactRestored($contactId, ContactKind::Company);
    }
}
