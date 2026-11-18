<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Events;

use Foresi\Events\Application\DeliverEvent;
use Foresi\Events\Application\RelayEvents;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Domain events: numbered by the relay and delivered once to each async
 * listener.
 */
final class EventsBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            RelayEvents::class => 'events',
            DeliverEvent::class => 'events',
        ];
    }
}
