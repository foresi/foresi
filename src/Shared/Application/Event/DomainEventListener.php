<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An asynchronous listener of one named contract event, in another module or
 * the same one. Delivery is at least once and unordered, so it is idempotent on
 * the event id. A synchronous listener is not this interface: it is a Symfony
 * event listener on the payload class and runs in the publisher's transaction.
 */
#[AutoconfigureTag('app.domain_event_listener')]
interface DomainEventListener
{
    /**
     * The event name this listener reacts to.
     */
    public function listensTo(): string;

    public function __invoke(RecordedEvent $event): void;
}
