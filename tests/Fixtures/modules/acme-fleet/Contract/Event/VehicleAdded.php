<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A vehicle was added to the firm's fleet. The payload is thin (ADR 0030): read
 * the vehicle itself for its name and plate.
 */
#[DomainEvent('acme-fleet.vehicle.added', version: 1)]
final readonly class VehicleAdded implements DomainEventPayload
{
    public function __construct(
        public string $vehicleId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('vehicle', $this->vehicleId);
    }
}
