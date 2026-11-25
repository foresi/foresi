<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Infrastructure\Mcp;

use Acme\Fleet\Application\Fleet;
use Acme\Fleet\Application\VehicleRefused;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;

/**
 * The firm's vehicles, for an MCP client; the tool is named with the module's
 * prefix, acme_fleet_ (ADR 0044).
 */
final readonly class ListVehiclesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private Fleet $fleet)
    {
    }

    public function name(): string
    {
        return 'acme_fleet_list_vehicles';
    }

    public function title(): string
    {
        return 'List vehicles';
    }

    public function scope(): string
    {
        return 'acme_fleet_vehicles:read';
    }

    public function description(): string
    {
        return 'Returns the vehicles of the firm\'s fleet in name order: {items} with the id, name, plate, driver_contact_id (the contact who drives it, or null) and created_at.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $vehicles = $this->fleet->all();
        } catch (VehicleRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(['items' => array_map(Fleet::json(...), $vehicles)], \sprintf('%d vehicles, in name order.', \count($vehicles)));
    }
}
