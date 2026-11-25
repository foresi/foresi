<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Infrastructure\Api;

use Acme\Fleet\Application\AddVehicleInput;
use Acme\Fleet\Application\Fleet;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * Adds a vehicle, over REST and MCP alike (ADR 0032).
 */
final readonly class AddVehicleHandler implements OperationHandler
{
    public function __construct(private Fleet $fleet)
    {
    }

    public static function input(): string
    {
        return AddVehicleInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddVehicleInput);
        $vehicle = $this->fleet->add($input);

        return new OperationOutput(Fleet::json($vehicle), \sprintf('Added the vehicle %s (%s) to the fleet.', $vehicle->name(), $vehicle->plate()));
    }
}
