<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Mcp;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

final readonly class ListActivityTypesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ActivityReads $reads, private ActivitiesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_activity_types';
    }

    public function title(): string
    {
        return 'List the activity types';
    }

    public function scope(): string
    {
        return 'activities:read';
    }

    public function description(): string
    {
        return 'Returns the activity types with their timing: "timed" (planned at a time with a duration), "date" (planned on a day) or "none" (no time, done when written). Archived ones only with include_archived.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['include_archived' => ['type' => 'boolean', 'description' => 'Also the archived types.']],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $types = $this->reads->types(true === ($arguments['include_archived'] ?? false));

            return new ToolOutput(['items' => array_map($this->json->type(...), $types)], \sprintf('%d activity types.', \count($types)));
        } catch (ActivitiesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
    }
}
