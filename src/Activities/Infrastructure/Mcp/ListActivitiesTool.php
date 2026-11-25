<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Mcp;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

final readonly class ListActivitiesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ActivityReads $reads, private ActivitiesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_activities';
    }

    public function title(): string
    {
        return 'List the activities of a record';
    }

    public function scope(): string
    {
        return 'activities:read';
    }

    public function description(): string
    {
        return 'Returns the timeline of a record, such as a contact: the planned activities first, the soonest on top, then the done ones, the latest first, each with its type, time, outcome, records and files. The trash is left out. Log or plan one with foresi_create_activity.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['linked_kind' => ['type' => 'string', 'description' => 'The kind of the record, such as "contact".'], 'linked_id' => ToolSchema::idSchema('The id of the record.')],
            'required' => ['linked_kind', 'linked_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $activities = $this->reads->timeline((string) ToolSchema::text($arguments, 'linked_kind'), (string) ToolSchema::text($arguments, 'linked_id'));

            return new ToolOutput(['items' => array_map($this->json->activity(...), $activities)], \sprintf('%d activities.', \count($activities)));
        } catch (ActivitiesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
    }
}
