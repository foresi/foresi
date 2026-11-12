<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Events\Application\RelayEvents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Relays after a commit that recorded events. The nudge may arrive stamped with
 * the tenant it was sent in; the relay ignores it, as it numbers the rows of
 * every tenant.
 */
#[AsMessageHandler]
final readonly class RelayEventsHandler
{
    public function __construct(private EventRelay $relay)
    {
    }

    public function __invoke(RelayEvents $message): void
    {
        $this->relay->relayAll();
    }
}
