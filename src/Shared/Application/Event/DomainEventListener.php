<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reacts to one named contract event. Delivery is at least once and unordered,
 * so a listener is idempotent on the event id. A synchronous listener runs in
 * the publisher's transaction and listens to its own module's events only.
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
