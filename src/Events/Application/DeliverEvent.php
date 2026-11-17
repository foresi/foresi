<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application;

/**
 * Hands one stored event to one async listener. The relay sends one per listener of the event's name when it numbers the row; the
 * listener is named by its service id. A renamed listener gets only the events
 * numbered after the rename; messages still queued under the old id are dropped.
 */
final readonly class DeliverEvent
{
    public function __construct(
        public string $eventId,
        public string $listener,
    ) {
    }
}
