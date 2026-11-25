<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Application;

use Acme\Fleet\Domain\Vehicle;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new vehicle over the API and MCP: the operation names, path, tool and
 * scope all start with the module's id (ADR 0044).
 */
#[Operation(
    name: 'acme-fleet.vehicles.add',
    method: 'POST',
    path: '/api/v1/acme-fleet/vehicles',
    tool: 'acme_fleet_add_vehicle',
    scope: 'acme_fleet_vehicles:write',
    title: 'Add a vehicle',
    description: 'Adds a vehicle to the firm\'s fleet, with its name, its licence plate and optionally the id of the contact who drives it. A plate is in the fleet once. Use acme_fleet_list_vehicles first, so the fleet holds no duplicate.',
    status: 201,
    staffOnly: true,
)]
final readonly class AddVehicleInput
{
    public function __construct(
        #[Field('What the vehicle is, such as "Delivery van".', maxLength: Vehicle::MAX_NAME)]
        public string $name,
        #[Field('The licence plate, such as "1AB 2345".', maxLength: Vehicle::MAX_PLATE)]
        public string $plate,
        #[Field('The id of the contact who drives it; left out for none.')]
        public ?string $driverContactId = null,
    ) {
    }
}
