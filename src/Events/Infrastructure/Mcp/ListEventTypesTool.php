<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Mcp;

use App\Events\Infrastructure\Api\ReadableEvents;
use App\Events\Infrastructure\Catalogue\EventType;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolOutput;

/**
 * The kinds of event the organisation's feed can hold, and the facts each one
 * carries, for the modules the token's person may read.
 */
final readonly class ListEventTypesTool implements ReadTool
{
    public function __construct(private ReadableEvents $events)
    {
    }

    public function name(): string
    {
        return 'foresi_list_event_types';
    }

    public function title(): string
    {
        return 'List event types';
    }

    public function description(): string
    {
        return 'Lists the kinds of domain event you can read with foresi_list_events: name, version, module, the type of subject the event is about and the names of the facts in its data. '
            .'Only the events of modules you may read are listed. Use the names, or "module.*", as the types argument of foresi_list_events.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $items = array_map(static fn (EventType $type): array => [
            'name' => $type->name,
            'version' => $type->version,
            'module' => $type->module->value,
            // Names are module.resource.action and the subject of an event is its resource.
            'subject_type' => explode('.', $type->name)[1],
            'fields' => $type->fields,
        ], $this->events->types());

        return new ToolOutput(['items' => $items], \sprintf('%d event types.', \count($items)));
    }
}
