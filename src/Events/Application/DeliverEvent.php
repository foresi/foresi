<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Hands one stored event to one async listener, in the tenant of the event. The
 * relay sends one per listener of the event's name; the listener is named by
 * its service id, so renaming its class delivers the events still in the log
 * to it again.
 */
final readonly class DeliverEvent implements TenantScopedMessage
{
    public function __construct(
        public string $eventId,
        public string $listener,
    ) {
    }
}
